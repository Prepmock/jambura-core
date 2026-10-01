<?php
namespace Jambura\LLM;

/**
 * A filter that trims a Prompt before it is formatted and sent.
 *
 * Registered with LLM::registerReducers(), and run by LLM::prompt() before
 * handlePrompt(). Reducers work on the Prompt object rather than the formatted
 * string, so one reducer serves every adapter whatever format or order it picks.
 *
 * Use one to cut what costs tokens without earning them: context sections a
 * particular kind of prompt never needs, instructions that do not apply, a
 * conversation history longer than it has to be.
 *
 * The prompt a reducer is given is already a copy, so changing it in place is
 * safe and never reaches the object the caller passed to prompt(). Return that
 * same prompt, or a new one built with Prompt::create(); either is accepted, and
 * whichever is returned goes on to the next reducer.
 *
 * Throw PromptRejected to stop the call outright, for a prompt that should not
 * reach a model at all. Reducers that follow do not run.
 */
interface Reducer
{
    /**
     * Prompt types this reducer applies to.
     *
     * Return an empty array to run for every prompt, including one with no type
     * set. Otherwise the reducer runs only for prompts whose getType() is one of
     * the names returned, and a prompt of any other type passes it untouched.
     *
     * @return string[] prompt type names, or [] for all of them
     */
    public function types(): array;

    /**
     * Returns the prompt with the noise taken out of it.
     *
     * @param Prompt $prompt a copy of the caller's prompt, safe to change
     * @return Prompt the prompt to carry on with
     *
     * @throws PromptRejected if the prompt should not be sent at all
     */
    public function reduce(Prompt $prompt): Prompt;
}
