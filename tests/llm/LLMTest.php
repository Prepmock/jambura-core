<?php

use Jambura\LLM;
use Jambura\LLM\LLMException;
use Jambura\LLM\Prompt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FakeModel extends LLM
{
    public array $sent = [];

    public function serialize(Prompt $prompt): array
    {
        return ['task' => $prompt->getTask()];
    }

    protected function send(array $payload): string
    {
        $this->sent[] = $payload;
        return 'reply';
    }
}

abstract class AbstractFakeModel extends LLM
{
}

class LLMTest extends TestCase
{
    protected function setUp(): void
    {
        LLM::forgetModels();
    }

    public function testUseReturnsOneInstancePerRegisteredModel(): void
    {
        LLM::registerModels([FakeModel::class]);
        $model = LLM::use(FakeModel::class);

        $this->assertInstanceOf(FakeModel::class, $model);
        $this->assertSame($model, LLM::use('\FakeModel'));
        $this->assertSame($model, LLM::use('fakemodel'));
    }

    public function testRegisteringAgainKeepsTheInstance(): void
    {
        LLM::registerModels([FakeModel::class]);
        $model = LLM::use(FakeModel::class);
        LLM::registerModels(['\FakeModel']);

        $this->assertSame($model, LLM::use(FakeModel::class));
    }

    public function testUseThrowsForAnUnregisteredModel(): void
    {
        LLM::registerModels([FakeModel::class]);

        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('Model class OtherModel is not registered');
        LLM::use('\OtherModel');
    }

    public static function invalidModelProvider(): array
    {
        return [
            'missing class' => ['NoSuchModel', 'does not exist'],
            'not an adapter' => [stdClass::class, 'must be a concrete subclass'],
            'abstract adapter' => [AbstractFakeModel::class, 'must be a concrete subclass'],
        ];
    }

    #[DataProvider('invalidModelProvider')]
    public function testRegisterModelsRejectsInvalidClasses(string $class, string $message): void
    {
        $this->expectException(LLMException::class);
        $this->expectExceptionMessage($message);
        LLM::registerModels([$class]);
    }

    public function testAdaptersCannotBeBuiltWithNew(): void
    {
        $this->expectException(Error::class);
        new FakeModel();
    }

    public function testPromptSendsTheSerializedPrompt(): void
    {
        LLM::registerModels([FakeModel::class]);
        $model = LLM::use(FakeModel::class);

        $this->assertSame('reply', $model->prompt(Prompt::create()->setTask('Summarize')));
        $this->assertSame([['task' => 'Summarize']], $model->sent);
    }

    public function testPromptRequiresATask(): void
    {
        LLM::registerModels([FakeModel::class]);

        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('A prompt needs a task');
        LLM::use(FakeModel::class)->prompt(Prompt::create()->setRole('Analyst')->setTask('  '));
    }
}
