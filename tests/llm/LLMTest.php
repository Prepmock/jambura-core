<?php

use Jambura\LLM;
use Jambura\LLM\Format;
use Jambura\LLM\LLMException;
use Jambura\LLM\Prompt;
use PHPUnit\Framework\TestCase;

class FakeModel extends LLM
{
    public $sent = [];

    protected function send($formattedPrompt, Prompt $prompt)
    {
        $this->sent[] = [$formattedPrompt, $prompt];
        return 'reply';
    }
}

abstract class AbstractFakeModel extends LLM
{
}

class TaskInOrderModel extends FakeModel
{
    protected $order = ['context', 'task'];
}

class UnknownSectionModel extends FakeModel
{
    protected $order = ['role', 'history'];
}

class RepeatedSectionModel extends FakeModel
{
    protected $order = ['role', 'context', 'role'];
}

class UnknownFormatModel extends FakeModel
{
    protected $format = 'yaml';
}

class LLMTest extends TestCase
{
    protected function setUp()
    {
        LLM::forgetModels();
    }

    public function testUseReturnsOneInstancePerRegisteredModel()
    {
        LLM::registerModels([FakeModel::class]);
        $model = LLM::use(FakeModel::class);

        $this->assertInstanceOf(FakeModel::class, $model);
        $this->assertSame($model, LLM::use('\FakeModel'));
        $this->assertSame($model, LLM::use('fakemodel'));
    }

    public function testRegisteringAgainKeepsTheInstance()
    {
        LLM::registerModels([FakeModel::class]);
        $model = LLM::use(FakeModel::class);
        LLM::registerModels(['\FakeModel']);

        $this->assertSame($model, LLM::use(FakeModel::class));
    }

    public function testUseThrowsForAnUnregisteredModel()
    {
        LLM::registerModels([FakeModel::class]);

        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('Model class OtherModel is not registered');
        LLM::use('\OtherModel');
    }

    public function invalidModelProvider()
    {
        return [
            'missing class' => ['NoSuchModel', 'does not exist'],
            'not an adapter' => [stdClass::class, 'must be a concrete subclass'],
            'abstract adapter' => [AbstractFakeModel::class, 'must be a concrete subclass'],
            'task in order' => [TaskInOrderModel::class, 'TaskInOrderModel::$order has unknown sections: task'],
            'unknown section in order' => [UnknownSectionModel::class, 'unknown sections: history'],
            'repeated section in order' => [RepeatedSectionModel::class, 'lists a section more than once'],
            'unknown format' => [UnknownFormatModel::class, 'is not a known format'],
        ];
    }

    /**
     * @dataProvider invalidModelProvider
     */
    public function testRegisterModelsRejectsInvalidClasses($class, $message)
    {
        $this->expectException(LLMException::class);
        $this->expectExceptionMessage($message);
        LLM::registerModels([$class]);
    }

    public function testAdaptersCannotBeBuiltWithNew()
    {
        $this->expectException(Error::class);
        new FakeModel();
    }

    public function testPromptSendsTheFormattedPromptWithThePrompt()
    {
        LLM::registerModels([FakeModel::class]);
        $model = LLM::use(FakeModel::class);
        $prompt = Prompt::create()->setTask('Summarize');

        $this->assertSame('reply', $model->prompt($prompt));
        $this->assertSame([['<task>Summarize</task>', $prompt]], $model->sent);
    }

    public function testPromptRequiresATask()
    {
        LLM::registerModels([FakeModel::class]);

        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('A prompt needs a task');
        LLM::use(FakeModel::class)->prompt(Prompt::create()->setRole('Analyst')->setTask('  '));
    }

    public function testFormatDefaultsToXml()
    {
        $this->assertSame(Format::XML, (new \ReflectionClass(FakeModel::class))->getDefaultProperties()['format']);
    }
}
