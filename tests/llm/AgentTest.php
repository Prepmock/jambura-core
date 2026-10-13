<?php

use Jambura\LLM\Agent;
use Jambura\LLM\LLMException;
use PHPUnit\Framework\TestCase;

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
}
