<?php

use AIModel\Deepseek;
use Jambura\LLM;
use Jambura\LLM\LLMException;
use Jambura\LLM\Prompt;
use PHPUnit\Framework\TestCase;

class DeepseekTest extends TestCase
{
    private Deepseek $deepseek;

    protected function setUp(): void
    {
        LLM::forgetModels();
        LLM::registerModels([Deepseek::class]);
        $this->deepseek = LLM::use(Deepseek::class);
    }

    public function testSerializesRoleAsSystemMessageAndSectionsAsJsonWithTaskLast(): void
    {
        $prompt = Prompt::create()
            ->setType('summary')
            ->setRole('You are a shipping analyst.')
            ->addContext('retrieved', 'Vessel ETA: 14:00')
            ->addContext('static', 'Port rules apply.')
            ->addInstruction('Be brief.')
            ->setTask('Summarize the delay.');

        $payload = $this->deepseek->serialize($prompt);

        $this->assertSame(['model', 'messages'], array_keys($payload));
        $this->assertSame('deepseek-v4-pro', $payload['model']);
        $this->assertSame(
            ['role' => 'system', 'content' => 'You are a shipping analyst.'],
            $payload['messages'][0]
        );
        $this->assertSame('user', $payload['messages'][1]['role']);

        $document = json_decode($payload['messages'][1]['content'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([
            'context' => [
                'static' => ['Port rules apply.'],
                'retrieved' => ['Vessel ETA: 14:00'],
            ],
            'instructions' => ['Be brief.'],
            'task' => 'Summarize the delay.',
        ], $document);
        $this->assertSame('task', array_key_last($document));
        $this->assertStringNotContainsString('summary', json_encode($payload));
    }

    public function testTaskOnlyPromptIsOneUserMessage(): void
    {
        $payload = $this->deepseek->serialize(Prompt::create()->setTask('Say OK.'));

        $this->assertCount(1, $payload['messages']);
        $this->assertSame('user', $payload['messages'][0]['role']);
        $this->assertSame(['task' => 'Say OK.'], json_decode($payload['messages'][0]['content'], true));
    }

    public function testPromptWithoutAnApiKeyThrowsBeforeCallingTheApi(): void
    {
        $original = getenv('DEEPSEEK_API_KEY');
        putenv('DEEPSEEK_API_KEY');
        try {
            $this->expectException(LLMException::class);
            $this->expectExceptionMessage('No Deepseek API key');
            $this->deepseek->prompt(Prompt::create()->setTask('Say OK.'));
        } finally {
            if ($original !== false) {
                putenv("DEEPSEEK_API_KEY=$original");
            }
        }
    }
}
