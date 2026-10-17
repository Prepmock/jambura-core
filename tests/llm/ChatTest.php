<?php

use Jambura\LLM;
use Jambura\LLM\ThreadStore;
use Jambura\LLM\LLMException;
use Jambura\LLM\Prompt;
use Jambura\LLM\Thread;
use PHPUnit\Framework\TestCase;

/**
 * A store that keeps conversations in a property, so a test can read what was
 * written without going near a filesystem.
 */
class InMemoryThreadStore implements ThreadStore
{
    /** @var array<string, array> */
    public array $threads = [];

    public function read(string $threadId): Thread
    {
        return new Thread($threadId, $this->threads[$threadId] ?? []);
    }

    public function append(string $threadId, array $messages): void
    {
        foreach ($messages as $message) {
            $this->threads[$threadId][] = $message;
        }
    }

    public function forget(string $threadId): void
    {
        unset($this->threads[$threadId]);
    }
}

/**
 * Records the messages and the formatted text that reached send(), and answers
 * with whatever the test queued up.
 */
class ChatModel extends LLM
{
    /** @var string[] replies handed out in order, 'reply' once they run out */
    public array $replies = [];

    /** @var list<array{formatted: string, messages: array}> */
    public array $sent = [];

    protected function send(string $formattedPrompt, Prompt $prompt): string
    {
        $this->sent[] = ['formatted' => $formattedPrompt, 'messages' => $prompt->getMessages()];
        return array_shift($this->replies) ?? 'reply';
    }
}

class OtherChatModel extends ChatModel
{
}

class ShortMemoryChatModel extends ChatModel
{
    protected int $keepMessages = 2;
}

class ForgetfulChatModel extends ChatModel
{
    protected int $keepMessages = 0;
}

class NegativeMemoryChatModel extends ChatModel
{
    protected int $keepMessages = -1;
}

class MessageReadingChatModel extends ChatModel
{
    protected bool $renderMessages = false;
}

class ContextlessChatModel extends ChatModel
{
    protected array $order = ['role', 'instructions'];
}

class MessageReadingContextlessChatModel extends ChatModel
{
    protected array $order = ['role', 'instructions'];
    protected bool $renderMessages = false;
}

class FailingChatModel extends ChatModel
{
    protected function send(string $formattedPrompt, Prompt $prompt): string
    {
        throw new LLMException('API is down');
    }
}

class ChatTest extends TestCase
{
    private InMemoryThreadStore $store;

    protected function setUp(): void
    {
        LLM::forgetModels();
        LLM::forgetThreadStore();
        LLM::registerModels([
            ChatModel::class,
            OtherChatModel::class,
            ShortMemoryChatModel::class,
            ForgetfulChatModel::class,
            MessageReadingChatModel::class,
            FailingChatModel::class,
        ]);

        $this->store = new InMemoryThreadStore();
        LLM::storeThreadsIn($this->store);
    }

    protected function tearDown(): void
    {
        LLM::forgetThreadStore();
    }

    private function ask(string $task): Prompt
    {
        return Prompt::create()->setTask($task);
    }

    public function testAFirstChatSendsNoHistoryAndStoresTheExchange(): void
    {
        $model = LLM::use(ChatModel::class);
        $model->replies = ['You have 12 days.'];

        $reply = $model->chat($this->ask('What is my leave balance?'), 'thread-1');

        $this->assertSame('You have 12 days.', $reply);
        $this->assertSame([], $model->sent[0]['messages']);
        $this->assertSame([
            ['role' => 'user', 'text' => 'What is my leave balance?', 'author' => null],
            ['role' => 'assistant', 'text' => 'You have 12 days.', 'author' => null],
        ], $this->store->threads['thread-1']);
    }

    public function testASecondChatCarriesTheFirstExchange(): void
    {
        $model = LLM::use(ChatModel::class);
        $model->replies = ['You have 12 days.', 'Four of them.'];

        $model->chat($this->ask('What is my leave balance?'), 'thread-1');
        $model->chat($this->ask('And carried over?'), 'thread-1');

        $this->assertSame([
            ['role' => 'user', 'text' => 'What is my leave balance?', 'author' => null],
            ['role' => 'assistant', 'text' => 'You have 12 days.', 'author' => null],
        ], $model->sent[1]['messages']);
        $this->assertCount(4, $this->store->threads['thread-1']);
    }

    /**
     * A thread id nobody has used starts a new history, rather than being an
     * error a caller has to tell apart from resuming one.
     */
    public function testAnUnusedThreadIdStartsAFreshHistory(): void
    {
        $model = LLM::use(ChatModel::class);

        $model->chat($this->ask('First'), 'thread-1');
        $model->chat($this->ask('Second'), 'thread-2');

        $this->assertSame([], $model->sent[1]['messages']);
        $this->assertCount(2, $this->store->threads['thread-2']);
    }

    public function testKeepMessagesSendsOnlyTheMostRecent(): void
    {
        $this->store->threads['thread-1'] = [
            ['role' => 'user', 'text' => 'One', 'author' => null],
            ['role' => 'assistant', 'text' => 'Two', 'author' => null],
            ['role' => 'user', 'text' => 'Three', 'author' => null],
            ['role' => 'assistant', 'text' => 'Four', 'author' => null],
        ];
        $model = LLM::use(ShortMemoryChatModel::class);

        $model->chat($this->ask('Five'), 'thread-1');

        $this->assertSame([
            ['role' => 'user', 'text' => 'Three', 'author' => null],
            ['role' => 'assistant', 'text' => 'Four', 'author' => null],
        ], $model->sent[0]['messages']);
    }

    /**
     * The older messages are not sent, but they are not dropped either: trimming is
     * about what reaches the model, not about what the store keeps.
     */
    public function testTrimmedMessagesStayInTheStore(): void
    {
        $this->store->threads['thread-1'] = [
            ['role' => 'user', 'text' => 'One', 'author' => null],
            ['role' => 'assistant', 'text' => 'Two', 'author' => null],
            ['role' => 'user', 'text' => 'Three', 'author' => null],
            ['role' => 'assistant', 'text' => 'Four', 'author' => null],
        ];

        LLM::use(ShortMemoryChatModel::class)->chat($this->ask('Five'), 'thread-1');

        $this->assertCount(6, $this->store->threads['thread-1']);
        $this->assertSame(['role' => 'user', 'text' => 'One', 'author' => null], $this->store->threads['thread-1'][0]);
    }

    /**
     * An adapter asking for no history still records the exchange, so switching
     * $keepMessages back on later finds a conversation waiting.
     */
    public function testKeepMessagesOfZeroSendsNoHistoryButStillStores(): void
    {
        $this->store->threads['thread-1'] = [
            ['role' => 'user', 'text' => 'One', 'author' => null],
            ['role' => 'assistant', 'text' => 'Two', 'author' => null],
        ];
        $model = LLM::use(ForgetfulChatModel::class);

        $model->chat($this->ask('Three'), 'thread-1');

        $this->assertSame([], $model->sent[0]['messages']);
        $this->assertCount(4, $this->store->threads['thread-1']);
    }

    public function testAFailedCallStoresNothing(): void
    {
        $model = LLM::use(FailingChatModel::class);

        try {
            $model->chat($this->ask('What is my leave balance?'), 'thread-1');
            $this->fail('Expected the adapter to throw');
        } catch (LLMException) {
        }

        $this->assertSame([], $this->store->read('thread-1')->messages());
    }

    /**
     * And a prompt rejected before it is ever sent stores nothing either.
     */
    public function testAPromptWithNoTaskStoresNothing(): void
    {
        $model = LLM::use(ChatModel::class);

        try {
            $model->chat(Prompt::create()->setTask('  '), 'thread-1');
            $this->fail('Expected a prompt with no task to be refused');
        } catch (LLMException $e) {
            $this->assertStringContainsString('A prompt needs a task', $e->getMessage());
        }

        $this->assertSame([], $this->store->read('thread-1')->messages());
    }

    /**
     * History belongs to the thread id, not to whoever answered last: the store
     * records no adapter class, so a conversation can be escalated to a second
     * model mid-thread.
     */
    public function testTwoAdaptersShareOneThreadId(): void
    {
        $first = LLM::use(ChatModel::class);
        $first->replies = ['You have 12 days.'];
        $second = LLM::use(OtherChatModel::class);

        $first->chat($this->ask('What is my leave balance?'), 'thread-1');
        $second->chat($this->ask('Explain the carry-over policy.'), 'thread-1');

        $this->assertSame([
            ['role' => 'user', 'text' => 'What is my leave balance?', 'author' => null],
            ['role' => 'assistant', 'text' => 'You have 12 days.', 'author' => null],
        ], $second->sent[0]['messages']);
    }

    /**
     * The point of messages: an adapter over a chat API reads them as messages and
     * maps them onto its own message list, rather than parsing them back out of
     * a blob of text.
     */
    public function testMessagesReachSendAsMessages(): void
    {
        $this->store->threads['thread-1'] = [
            ['role' => 'user', 'text' => 'What is my leave balance?', 'author' => null],
            ['role' => 'assistant', 'text' => 'You have 12 days.', 'author' => null],
        ];
        $model = LLM::use(ChatModel::class);

        $model->chat($this->ask('And carried over?'), 'thread-1');

        $this->assertSame([
            ['role' => 'user', 'text' => 'What is my leave balance?', 'author' => null],
            ['role' => 'assistant', 'text' => 'You have 12 days.', 'author' => null],
        ], $model->sent[0]['messages']);
    }

    /**
     * And an adapter that does not read them loses nothing: handlePrompt()
     * renders the same messages into the conversation context section.
     */
    public function testAnAdapterThatIgnoresMessagesGetsThemRendered(): void
    {
        $this->store->threads['thread-1'] = [
            ['role' => 'user', 'text' => 'What is my leave balance?', 'author' => null],
            ['role' => 'assistant', 'text' => 'You have 12 days.', 'author' => null],
        ];
        $model = LLM::use(ChatModel::class);

        $model->chat($this->ask('And carried over?'), 'thread-1');

        $this->assertSame(
            "<context>\n"
            . "  <conversation>\n"
            . "    <item>User: What is my leave balance?</item>\n"
            . "    <item>Assistant: You have 12 days.</item>\n"
            . "  </conversation>\n"
            . "</context>\n"
            . '<task>And carried over?</task>',
            $model->sent[0]['formatted']
        );
    }

    /**
     * An adapter that builds its own message list from getMessages() would
     * otherwise send the history twice, once as messages and once as prose.
     * $renderMessages keeps it out of the formatted text without also costing the
     * adapter the rest of its context, which is what dropping `context` from
     * $order would do.
     */
    public function testRenderMessagesOffKeepsTheConversationOutOfTheFormattedPrompt(): void
    {
        $this->store->threads['thread-1'] = [
            ['role' => 'user', 'text' => 'What is my leave balance?', 'author' => null],
            ['role' => 'assistant', 'text' => 'You have 12 days.', 'author' => null],
        ];
        $model = LLM::use(MessageReadingChatModel::class);

        $model->chat($this->ask('And carried over?')->addContext('static', 'Leave policy'), 'thread-1');

        $this->assertStringNotContainsString('conversation', $model->sent[0]['formatted']);
        $this->assertStringContainsString('<item>Leave policy</item>', $model->sent[0]['formatted']);
        $this->assertCount(2, $model->sent[0]['messages']);
    }

    public function testChatDoesNotChangeTheCallersPrompt(): void
    {
        $this->store->threads['thread-1'] = [
            ['role' => 'user', 'text' => 'One', 'author' => null],
            ['role' => 'assistant', 'text' => 'Two', 'author' => null],
        ];
        $model = LLM::use(ChatModel::class);
        $prompt = $this->ask('Three');

        $model->chat($prompt, 'thread-1');
        $model->chat($prompt, 'thread-1');

        // Were the messages put on the caller's prompt, the second call would send
        // the first call's history twice over.
        $this->assertSame([], $prompt->getMessages());
        $this->assertCount(4, $model->sent[1]['messages']);
    }

    public function testStoredHistoryComesBeforeMessagesAddedByHand(): void
    {
        $this->store->threads['thread-1'] = [
            ['role' => 'user', 'text' => 'Stored', 'author' => null],
        ];
        $model = LLM::use(ChatModel::class);

        $model->chat($this->ask('Three')->addMessage(Prompt::ASSISTANT, 'Added by hand'), 'thread-1');

        $this->assertSame([
            ['role' => 'user', 'text' => 'Stored', 'author' => null],
            ['role' => 'assistant', 'text' => 'Added by hand', 'author' => null],
        ], $model->sent[0]['messages']);
    }

    public function testChatRejectsABlankThreadId(): void
    {
        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('A chat needs a thread id');
        LLM::use(ChatModel::class)->chat($this->ask('Hello'), '  ');
    }

    public function testRegisterModelsRejectsNegativeKeepMessages(): void
    {
        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('NegativeMemoryChatModel::$keepMessages cannot be negative');
        LLM::registerModels([NegativeMemoryChatModel::class]);
    }

    public function testTheStoreHandsBackTheWholeThread(): void
    {
        $model = LLM::use(ChatModel::class);
        $model->replies = ['You have 12 days.'];

        $model->chat($this->ask('What is my leave balance?'), 'thread-1');

        $this->assertSame([
            ['role' => 'user', 'text' => 'What is my leave balance?', 'author' => null],
            ['role' => 'assistant', 'text' => 'You have 12 days.', 'author' => null],
        ], LLM::threads()->read('thread-1')->messages());
        $this->assertSame([], LLM::threads()->read('never-used')->messages());
    }

    public function testForgettingAThreadStartsTheThreadOver(): void
    {
        $model = LLM::use(ChatModel::class);
        $model->chat($this->ask('First'), 'thread-1');

        LLM::threads()->forget('thread-1');
        $model->chat($this->ask('Second'), 'thread-1');

        $this->assertSame([], $model->sent[1]['messages']);
        $this->assertCount(2, $this->store->threads['thread-1']);
    }

    public function testForgettingAnUnusedSessionIsNotAnError(): void
    {
        LLM::threads()->forget('never-used');

        $this->assertSame([], LLM::threads()->read('never-used')->messages());
    }

    public function testStoreThreadsInReplacesTheStore(): void
    {
        $model = LLM::use(ChatModel::class);
        $model->chat($this->ask('First'), 'thread-1');

        $second = new InMemoryThreadStore();
        LLM::storeThreadsIn($second);
        $model->chat($this->ask('Second'), 'thread-1');

        $this->assertCount(2, $this->store->threads['thread-1']);
        $this->assertCount(2, $second->threads['thread-1']);
        $this->assertSame([], $model->sent[1]['messages']);
    }

    /**
     * prompt() is the one-shot it always was: it neither reads a history nor
     * writes one, whatever is stored under a thread id.
     */
    public function testPromptStillCarriesNoHistory(): void
    {
        $this->store->threads['thread-1'] = [
            ['role' => 'user', 'text' => 'One', 'author' => null],
        ];
        $model = LLM::use(ChatModel::class);

        $model->prompt($this->ask('Two'));

        $this->assertSame([], $model->sent[0]['messages']);
        $this->assertCount(1, $this->store->threads['thread-1']);
    }
    /**
     * The combination that would lose a conversation in silence: messages are
     * rendered into the context section, but context is not ordered, so they go
     * nowhere - and the store keeps growing meanwhile.
     */
    public function testAnAdapterThatRendersMessagesMustOrderContext(): void
    {
        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('leaves context out, so a conversation would go nowhere');
        LLM::registerModels([ContextlessChatModel::class]);
    }

    public function testAnAdapterThatReadsMessagesItselfNeedNotOrderContext(): void
    {
        LLM::registerModels([MessageReadingContextlessChatModel::class]);

        $this->assertInstanceOf(
            MessageReadingContextlessChatModel::class,
            LLM::use(MessageReadingContextlessChatModel::class)
        );
    }

    public function testAnAuthorIsRenderedInsteadOfTheRole(): void
    {
        $model = LLM::use(ChatModel::class);
        $prompt = Prompt::create()->setTask('And customs?');
        $prompt->addMessage(Prompt::USER, 'What is the ETA?', 'Kamrul');
        $prompt->addMessage(Prompt::ASSISTANT, 'Berth 4, 14:00.');
        $prompt->addMessage(Prompt::USER, 'Thanks', 'Laltu');

        $model->chat($prompt, 'thread-1');

        $formatted = $model->sent[0]['formatted'];
        $this->assertStringContainsString('Kamrul: What is the ETA?', $formatted);
        $this->assertStringContainsString('Assistant: Berth 4, 14:00.', $formatted);
        $this->assertStringContainsString('Laltu: Thanks', $formatted);
    }

    public function testAnAuthorSurvivesTheStoreAndComesBackOnTheNextChat(): void
    {
        $model = LLM::use(ChatModel::class);
        $this->store->append('thread-1', [
            ['role' => Prompt::USER, 'text' => 'What is the ETA?', 'author' => 'Kamrul'],
        ]);

        $model->chat(Prompt::create()->setTask('And customs?'), 'thread-1');

        $this->assertSame(
            [['role' => 'user', 'text' => 'What is the ETA?', 'author' => 'Kamrul']],
            array_slice($model->sent[0]['messages'], 0, 1)
        );
    }

}
