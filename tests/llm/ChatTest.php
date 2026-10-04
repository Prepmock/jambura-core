<?php

use Jambura\LLM;
use Jambura\LLM\ChatStore;
use Jambura\LLM\LLMException;
use Jambura\LLM\Prompt;
use PHPUnit\Framework\TestCase;

/**
 * A store that keeps conversations in a property, so a test can read what was
 * written without going near a filesystem.
 */
class InMemoryChatStore implements ChatStore
{
    /** @var array<string, list<array{role: string, text: string}>> */
    public array $sessions = [];

    public function turns(string $sessionId): array
    {
        return $this->sessions[$sessionId] ?? [];
    }

    public function append(string $sessionId, array $turns): void
    {
        foreach ($turns as $turn) {
            $this->sessions[$sessionId][] = $turn;
        }
    }

    public function forget(string $sessionId): void
    {
        unset($this->sessions[$sessionId]);
    }
}

/**
 * Records the turns and the formatted text that reached send(), and answers
 * with whatever the test queued up.
 */
class ChatModel extends LLM
{
    /** @var string[] replies handed out in order, 'reply' once they run out */
    public array $replies = [];

    /** @var list<array{formatted: string, turns: array}> */
    public array $sent = [];

    protected function send(string $formattedPrompt, Prompt $prompt): string
    {
        $this->sent[] = ['formatted' => $formattedPrompt, 'turns' => $prompt->getTurns()];
        return array_shift($this->replies) ?? 'reply';
    }
}

class OtherChatModel extends ChatModel
{
}

class ShortMemoryChatModel extends ChatModel
{
    protected int $keepTurns = 2;
}

class ForgetfulChatModel extends ChatModel
{
    protected int $keepTurns = 0;
}

class NegativeMemoryChatModel extends ChatModel
{
    protected int $keepTurns = -1;
}

class TurnReadingChatModel extends ChatModel
{
    protected bool $renderTurns = false;
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
    private InMemoryChatStore $store;

    protected function setUp(): void
    {
        LLM::forgetModels();
        LLM::forgetChatStore();
        LLM::registerModels([
            ChatModel::class,
            OtherChatModel::class,
            ShortMemoryChatModel::class,
            ForgetfulChatModel::class,
            TurnReadingChatModel::class,
            FailingChatModel::class,
        ]);

        $this->store = new InMemoryChatStore();
        LLM::storeChatsIn($this->store);
    }

    protected function tearDown(): void
    {
        LLM::forgetChatStore();
    }

    private function ask(string $task): Prompt
    {
        return Prompt::create()->setTask($task);
    }

    public function testAFirstChatSendsNoHistoryAndStoresTheExchange(): void
    {
        $model = LLM::use(ChatModel::class);
        $model->replies = ['You have 12 days.'];

        $reply = $model->chat($this->ask('What is my leave balance?'), 'session-1');

        $this->assertSame('You have 12 days.', $reply);
        $this->assertSame([], $model->sent[0]['turns']);
        $this->assertSame([
            ['role' => 'user', 'text' => 'What is my leave balance?'],
            ['role' => 'assistant', 'text' => 'You have 12 days.'],
        ], $this->store->sessions['session-1']);
    }

    public function testASecondChatCarriesTheFirstExchange(): void
    {
        $model = LLM::use(ChatModel::class);
        $model->replies = ['You have 12 days.', 'Four of them.'];

        $model->chat($this->ask('What is my leave balance?'), 'session-1');
        $model->chat($this->ask('And carried over?'), 'session-1');

        $this->assertSame([
            ['role' => 'user', 'text' => 'What is my leave balance?'],
            ['role' => 'assistant', 'text' => 'You have 12 days.'],
        ], $model->sent[1]['turns']);
        $this->assertCount(4, $this->store->sessions['session-1']);
    }

    /**
     * A session id nobody has used starts a new history, rather than being an
     * error a caller has to tell apart from resuming one.
     */
    public function testAnUnusedSessionIdStartsAFreshHistory(): void
    {
        $model = LLM::use(ChatModel::class);

        $model->chat($this->ask('First'), 'session-1');
        $model->chat($this->ask('Second'), 'session-2');

        $this->assertSame([], $model->sent[1]['turns']);
        $this->assertCount(2, $this->store->sessions['session-2']);
    }

    public function testKeepTurnsSendsOnlyTheMostRecentTurns(): void
    {
        $this->store->sessions['session-1'] = [
            ['role' => 'user', 'text' => 'One'],
            ['role' => 'assistant', 'text' => 'Two'],
            ['role' => 'user', 'text' => 'Three'],
            ['role' => 'assistant', 'text' => 'Four'],
        ];
        $model = LLM::use(ShortMemoryChatModel::class);

        $model->chat($this->ask('Five'), 'session-1');

        $this->assertSame([
            ['role' => 'user', 'text' => 'Three'],
            ['role' => 'assistant', 'text' => 'Four'],
        ], $model->sent[0]['turns']);
    }

    /**
     * The older turns are not sent, but they are not dropped either: trimming is
     * about what reaches the model, not about what the store keeps.
     */
    public function testTrimmedTurnsStayInTheStore(): void
    {
        $this->store->sessions['session-1'] = [
            ['role' => 'user', 'text' => 'One'],
            ['role' => 'assistant', 'text' => 'Two'],
            ['role' => 'user', 'text' => 'Three'],
            ['role' => 'assistant', 'text' => 'Four'],
        ];

        LLM::use(ShortMemoryChatModel::class)->chat($this->ask('Five'), 'session-1');

        $this->assertCount(6, $this->store->sessions['session-1']);
        $this->assertSame(['role' => 'user', 'text' => 'One'], $this->store->sessions['session-1'][0]);
    }

    /**
     * An adapter asking for no history still records the exchange, so switching
     * $keepTurns back on later finds a conversation waiting.
     */
    public function testKeepTurnsOfZeroSendsNoHistoryButStillStores(): void
    {
        $this->store->sessions['session-1'] = [
            ['role' => 'user', 'text' => 'One'],
            ['role' => 'assistant', 'text' => 'Two'],
        ];
        $model = LLM::use(ForgetfulChatModel::class);

        $model->chat($this->ask('Three'), 'session-1');

        $this->assertSame([], $model->sent[0]['turns']);
        $this->assertCount(4, $this->store->sessions['session-1']);
    }

    public function testAFailedCallStoresNothing(): void
    {
        $model = LLM::use(FailingChatModel::class);

        try {
            $model->chat($this->ask('What is my leave balance?'), 'session-1');
            $this->fail('Expected the adapter to throw');
        } catch (LLMException) {
        }

        $this->assertSame([], $this->store->turns('session-1'));
    }

    /**
     * And a prompt rejected before it is ever sent stores nothing either.
     */
    public function testAPromptWithNoTaskStoresNothing(): void
    {
        $model = LLM::use(ChatModel::class);

        try {
            $model->chat(Prompt::create()->setTask('  '), 'session-1');
            $this->fail('Expected a prompt with no task to be refused');
        } catch (LLMException $e) {
            $this->assertStringContainsString('A prompt needs a task', $e->getMessage());
        }

        $this->assertSame([], $this->store->turns('session-1'));
    }

    /**
     * History belongs to the session id, not to whoever answered last: the store
     * records no adapter class, so a conversation can be escalated to a second
     * model mid-thread.
     */
    public function testTwoAdaptersShareOneSessionId(): void
    {
        $first = LLM::use(ChatModel::class);
        $first->replies = ['You have 12 days.'];
        $second = LLM::use(OtherChatModel::class);

        $first->chat($this->ask('What is my leave balance?'), 'session-1');
        $second->chat($this->ask('Explain the carry-over policy.'), 'session-1');

        $this->assertSame([
            ['role' => 'user', 'text' => 'What is my leave balance?'],
            ['role' => 'assistant', 'text' => 'You have 12 days.'],
        ], $second->sent[0]['turns']);
    }

    /**
     * The point of turns: an adapter over a chat API reads them as turns and
     * maps them onto its own message list, rather than parsing them back out of
     * a blob of text.
     */
    public function testTurnsReachSendAsTurns(): void
    {
        $this->store->sessions['session-1'] = [
            ['role' => 'user', 'text' => 'What is my leave balance?'],
            ['role' => 'assistant', 'text' => 'You have 12 days.'],
        ];
        $model = LLM::use(ChatModel::class);

        $model->chat($this->ask('And carried over?'), 'session-1');

        $this->assertSame([
            ['role' => 'user', 'text' => 'What is my leave balance?'],
            ['role' => 'assistant', 'text' => 'You have 12 days.'],
        ], $model->sent[0]['turns']);
    }

    /**
     * And an adapter that does not read them loses nothing: handlePrompt()
     * renders the same turns into the conversation context section.
     */
    public function testAnAdapterThatIgnoresTurnsGetsThemRendered(): void
    {
        $this->store->sessions['session-1'] = [
            ['role' => 'user', 'text' => 'What is my leave balance?'],
            ['role' => 'assistant', 'text' => 'You have 12 days.'],
        ];
        $model = LLM::use(ChatModel::class);

        $model->chat($this->ask('And carried over?'), 'session-1');

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
     * An adapter that builds its own message list from getTurns() would
     * otherwise send the history twice, once as messages and once as prose.
     * $renderTurns keeps it out of the formatted text without also costing the
     * adapter the rest of its context, which is what dropping `context` from
     * $order would do.
     */
    public function testRenderTurnsOffKeepsTheConversationOutOfTheFormattedPrompt(): void
    {
        $this->store->sessions['session-1'] = [
            ['role' => 'user', 'text' => 'What is my leave balance?'],
            ['role' => 'assistant', 'text' => 'You have 12 days.'],
        ];
        $model = LLM::use(TurnReadingChatModel::class);

        $model->chat($this->ask('And carried over?')->addContext('static', 'Leave policy'), 'session-1');

        $this->assertStringNotContainsString('conversation', $model->sent[0]['formatted']);
        $this->assertStringContainsString('<item>Leave policy</item>', $model->sent[0]['formatted']);
        $this->assertCount(2, $model->sent[0]['turns']);
    }

    public function testChatDoesNotChangeTheCallersPrompt(): void
    {
        $this->store->sessions['session-1'] = [
            ['role' => 'user', 'text' => 'One'],
            ['role' => 'assistant', 'text' => 'Two'],
        ];
        $model = LLM::use(ChatModel::class);
        $prompt = $this->ask('Three');

        $model->chat($prompt, 'session-1');
        $model->chat($prompt, 'session-1');

        // Were the turns put on the caller's prompt, the second call would send
        // the first call's history twice over.
        $this->assertSame([], $prompt->getTurns());
        $this->assertCount(4, $model->sent[1]['turns']);
    }

    public function testStoredHistoryComesBeforeTurnsAddedByHand(): void
    {
        $this->store->sessions['session-1'] = [
            ['role' => 'user', 'text' => 'Stored'],
        ];
        $model = LLM::use(ChatModel::class);

        $model->chat($this->ask('Three')->addTurn(Prompt::ASSISTANT, 'Added by hand'), 'session-1');

        $this->assertSame([
            ['role' => 'user', 'text' => 'Stored'],
            ['role' => 'assistant', 'text' => 'Added by hand'],
        ], $model->sent[0]['turns']);
    }

    public function testChatRejectsABlankSessionId(): void
    {
        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('A chat needs a session id');
        LLM::use(ChatModel::class)->chat($this->ask('Hello'), '  ');
    }

    public function testRegisterModelsRejectsNegativeKeepTurns(): void
    {
        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('NegativeMemoryChatModel::$keepTurns cannot be negative');
        LLM::registerModels([NegativeMemoryChatModel::class]);
    }

    public function testChatTurnsReturnsTheStoredThread(): void
    {
        $model = LLM::use(ChatModel::class);
        $model->replies = ['You have 12 days.'];

        $model->chat($this->ask('What is my leave balance?'), 'session-1');

        $this->assertSame([
            ['role' => 'user', 'text' => 'What is my leave balance?'],
            ['role' => 'assistant', 'text' => 'You have 12 days.'],
        ], LLM::chatTurns('session-1'));
        $this->assertSame([], LLM::chatTurns('never-used'));
    }

    public function testForgetChatStartsTheSessionOver(): void
    {
        $model = LLM::use(ChatModel::class);
        $model->chat($this->ask('First'), 'session-1');

        LLM::forgetChat('session-1');
        $model->chat($this->ask('Second'), 'session-1');

        $this->assertSame([], $model->sent[1]['turns']);
        $this->assertCount(2, $this->store->sessions['session-1']);
    }

    public function testForgettingAnUnusedSessionIsNotAnError(): void
    {
        LLM::forgetChat('never-used');

        $this->assertSame([], LLM::chatTurns('never-used'));
    }

    public function testStoreChatsInReplacesTheStore(): void
    {
        $model = LLM::use(ChatModel::class);
        $model->chat($this->ask('First'), 'session-1');

        $second = new InMemoryChatStore();
        LLM::storeChatsIn($second);
        $model->chat($this->ask('Second'), 'session-1');

        $this->assertCount(2, $this->store->sessions['session-1']);
        $this->assertCount(2, $second->sessions['session-1']);
        $this->assertSame([], $model->sent[1]['turns']);
    }

    /**
     * prompt() is the one-shot it always was: it neither reads a history nor
     * writes one, whatever is stored under a session id.
     */
    public function testPromptStillCarriesNoHistory(): void
    {
        $this->store->sessions['session-1'] = [
            ['role' => 'user', 'text' => 'One'],
        ];
        $model = LLM::use(ChatModel::class);

        $model->prompt($this->ask('Two'));

        $this->assertSame([], $model->sent[0]['turns']);
        $this->assertCount(1, $this->store->sessions['session-1']);
    }
}
