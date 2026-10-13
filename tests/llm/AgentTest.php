<?php

use Jambura\LLM\Agent;
use Jambura\LLM\LLMException;
use Jambura\LLM\Prompt;
use Jambura\LLM\Run;
use PHPUnit\Framework\TestCase;

/**
 * An adapter that hands back the prompt it was given, so a test can see exactly
 * what the agent sent.
 */
class AgentEchoModel extends \Jambura\LLM
{
    protected function send(string $formattedPrompt, Prompt $prompt): string
    {
        return $formattedPrompt;
    }
}

/**
 * An adapter whose provider is down.
 */
class AgentFailingModel extends \Jambura\LLM
{
    protected function send(string $formattedPrompt, Prompt $prompt): string
    {
        throw new LLMException('provider down');
    }
}

class AgentNotAModel
{
}

class AgentTest extends TestCase
{
    protected function setUp(): void
    {
        Agent::forgetAgents();
        \Jambura\LLM::forgetModels();
    }

    public function testMakeDefinesAnAgentThatUseReturns(): void
    {
        $agent = Agent::make('researcher');

        $this->assertSame('researcher', $agent->name());
        $this->assertSame($agent, Agent::use('researcher'));
        $this->assertTrue(Agent::isDefined('researcher'));
        $this->assertFalse(Agent::isDefined('writer'));
    }

    public function testMakingTheSameNameTwiceThrows(): void
    {
        Agent::make('researcher');

        $this->expectException(LLMException::class);
        $this->expectExceptionMessage("Agent 'researcher' is already defined");
        Agent::make('researcher');
    }

    public function testUsingAnUndefinedAgentThrows(): void
    {
        $this->expectException(LLMException::class);
        $this->expectExceptionMessage("Agent 'researcher' is not defined. Call Jambura\LLM\Agent::make('researcher') first");
        Agent::use('researcher');
    }

    public function testForgetAgentsClearsTheRegistry(): void
    {
        Agent::make('researcher');

        Agent::forgetAgents();

        $this->assertFalse(Agent::isDefined('researcher'));
        $this->assertSame('researcher', Agent::make('researcher')->name());
    }

    public function testModelRegistersItsAdapterWithTheLlmRegistry(): void
    {
        Agent::make('researcher')->model(AgentEchoModel::class);

        $this->assertInstanceOf(AgentEchoModel::class, \Jambura\LLM::use(AgentEchoModel::class));
    }

    public function testAModelNamingAMissingClassThrows(): void
    {
        $agent = Agent::make('researcher');

        $this->expectException(LLMException::class);
        $this->expectExceptionMessage("Agent 'researcher' names the adapter class AIModel\Nope, which does not exist");
        $agent->model('AIModel\Nope');
    }

    public function testAModelThatIsNotAnAdapterThrows(): void
    {
        $agent = Agent::make('researcher');

        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('so AgentNotAModel must be an adapter extending Jambura\LLM');
        $agent->model(AgentNotAModel::class);
    }

    public function testAnInvalidModelIsNotRegistered(): void
    {
        try {
            Agent::make('researcher')->model(AgentNotAModel::class);
            $this->fail('Expected an LLMException');
        } catch (LLMException $e) {
        }

        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('is not registered');
        \Jambura\LLM::use(AgentNotAModel::class);
    }

    public function testConfigureRecordsTheSettingsAndMerges(): void
    {
        $agent = Agent::make('researcher')
            ->configure(['max_steps' => 6, 'token_budget' => 20000])
            ->configure(['max_steps' => 3]);

        $this->assertSame(['max_steps' => 3, 'token_budget' => 20000], $agent->settings());
    }

    public function testConfiguringAnUnknownSettingThrowsAndNamesTheAllowedOnes(): void
    {
        $agent = Agent::make('researcher');

        $this->expectException(LLMException::class);
        $this->expectExceptionMessage(
            "Agent 'researcher' has no setting temperature. Use one of: max_steps, token_budget"
        );
        $agent->configure(['max_steps' => 6, 'temperature' => 0.2]);
    }

    public function testAnUnknownSettingLeavesTheSettingsAsTheyWere(): void
    {
        $agent = Agent::make('researcher')->configure(['max_steps' => 6]);

        try {
            $agent->configure(['max_steps' => 2, 'temperature' => 0.2]);
            $this->fail('Expected an LLMException');
        } catch (LLMException $e) {
        }

        $this->assertSame(['max_steps' => 6], $agent->settings());
    }

    public function testToolsAreRecordedInOrder(): void
    {
        $agent = Agent::make('researcher')
            ->tools('search_docs', 'read_file')
            ->tools('summarise');

        $this->assertSame(['search_docs', 'read_file', 'summarise'], $agent->definedTools());
    }

    public function testEveryDefinitionMethodReturnsTheSameAgent(): void
    {
        $agent = Agent::make('researcher');

        $this->assertSame($agent, $agent->model(AgentEchoModel::class));
        $this->assertSame($agent, $agent->role('You are a research assistant.'));
        $this->assertSame($agent, $agent->instructions('Cite the context you use.', 'Be brief.'));
        $this->assertSame($agent, $agent->configure(['max_steps' => 6]));
        $this->assertSame($agent, $agent->tools('search_docs'));
    }

    public function testStartBuildsThePromptFromRoleInstructionsAndTask(): void
    {
        $this->researcher();

        $prompt = Agent::use('researcher')->start('What changed in the shipping rules?')->prompt();

        $this->assertSame('You are a research assistant.', $prompt->getRole());
        $this->assertSame(['Cite the context you use.', 'Be brief.'], $prompt->getInstructions());
        $this->assertSame('What changed in the shipping rules?', $prompt->getTask());
        $this->assertSame([], $prompt->getContext());
    }

    public function testAskSendsRoleAndInstructionsBeforeTheTask(): void
    {
        $this->researcher();

        $this->assertSame(
            "<role>You are a research assistant.</role>\n"
            . "<instructions>\n"
            . "  <instruction>Cite the context you use.</instruction>\n"
            . "  <instruction>Be brief.</instruction>\n"
            . "</instructions>\n"
            . '<task>What changed in the shipping rules?</task>',
            Agent::use('researcher')->ask('What changed in the shipping rules?')
        );
    }

    public function testAnAgentWithOnlyAModelSendsJustTheTask(): void
    {
        Agent::make('plain')->model(AgentEchoModel::class);

        $this->assertSame('<task>Say hello.</task>', Agent::use('plain')->ask('Say hello.'));
    }

    public function testStartReturnsAFinishedRun(): void
    {
        $this->researcher();

        $run = Agent::use('researcher')->start('What changed?');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $run->id());
        $this->assertSame('researcher', $run->agent());
        $this->assertSame(Run::DONE, $run->status());
        $this->assertSame(1, $run->step());
        $this->assertSame(['steps' => 1, 'tokens' => 0], $run->spent());
        $this->assertSame(
            [['step' => 0, 'model' => AgentEchoModel::class, 'reply' => $run->answer()]],
            $run->turns()
        );
        $this->assertSame(['started', 'model_call', 'done'], array_column($run->trace(), 'event'));
        $this->assertStringEndsWith('<task>What changed?</task>', $run->answer());
        $this->assertNull($run->error());
    }

    public function testRunSendsTheCallersPromptWithTheAgentsDefinitionAdded(): void
    {
        $this->researcher();
        $prompt = Prompt::create()
            ->addContext('retrieved', 'Shipping rules v2: pallets over 1t need a permit.')
            ->addInstruction('Answer in one sentence.')
            ->setTask('What changed in the shipping rules?');

        $answer = Agent::use('researcher')->run($prompt);

        $this->assertSame(
            "<role>You are a research assistant.</role>\n"
            . "<context>\n"
            . "  <retrieved>\n"
            . "    <item>Shipping rules v2: pallets over 1t need a permit.</item>\n"
            . "  </retrieved>\n"
            . "</context>\n"
            . "<instructions>\n"
            . "  <instruction>Answer in one sentence.</instruction>\n"
            . "  <instruction>Cite the context you use.</instruction>\n"
            . "  <instruction>Be brief.</instruction>\n"
            . "</instructions>\n"
            . '<task>What changed in the shipping rules?</task>',
            $answer
        );
    }

    public function testRunLeavesTheCallersPromptUnchanged(): void
    {
        $this->researcher();
        $prompt = Prompt::create()->addInstruction('Answer in one sentence.')->setTask('What changed?');

        Agent::use('researcher')->run($prompt);

        $this->assertNull($prompt->getRole());
        $this->assertSame(['Answer in one sentence.'], $prompt->getInstructions());
    }

    public function testRunKeepsARoleTheCallerSet(): void
    {
        $this->researcher();
        $prompt = Prompt::create()->setRole('You are an auditor.')->setTask('What changed?');

        $this->assertStringStartsWith(
            '<role>You are an auditor.</role>',
            Agent::use('researcher')->run($prompt)
        );
    }

    public function testCallingAnAgentWithoutAModelThrows(): void
    {
        Agent::make('researcher')->role('You are a research assistant.');

        $this->expectException(LLMException::class);
        $this->expectExceptionMessage("Agent 'researcher' has no model. Call model() first");
        Agent::use('researcher')->ask('What changed?');
    }

    public function testTheAdaptersExceptionIsRethrown(): void
    {
        Agent::make('researcher')->model(AgentFailingModel::class);

        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('provider down');
        Agent::use('researcher')->ask('What changed?');
    }

    public function testTwoRunsOfOneAgentShareNoState(): void
    {
        $this->researcher();
        $definition = serialize(Agent::use('researcher'));

        $first = Agent::use('researcher')->start('First task');
        $firstState = $first->toArray();
        $second = Agent::use('researcher')->start('Second task');

        $this->assertNotSame($first->id(), $second->id());
        $this->assertNotSame($first->prompt(), $second->prompt());
        $this->assertSame($firstState, $first->toArray());
        $this->assertSame('First task', $first->prompt()->getTask());
        $this->assertSame('Second task', $second->prompt()->getTask());
        $this->assertSame(['Cite the context you use.', 'Be brief.'], $second->prompt()->getInstructions());
        $this->assertCount(1, $second->turns());
        $this->assertSame($definition, serialize(Agent::use('researcher')));
    }

    private function researcher(): Agent
    {
        return Agent::make('researcher')
            ->model(AgentEchoModel::class)
            ->role('You are a research assistant.')
            ->instructions('Cite the context you use.', 'Be brief.')
            ->configure(['max_steps' => 6, 'token_budget' => 20000]);
    }
}
