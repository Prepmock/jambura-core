<?php

use Jambura\LLM\Agent;
use Jambura\LLM\LLMException;
use Jambura\LLM\Prompt;
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
}
