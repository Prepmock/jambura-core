<?php
namespace Jambura\LLM;

/**
 * A prompt a reducer refused to let through.
 *
 * Thrown from Reducer::reduce() when a prompt should not reach a model at all,
 * and left to reach the caller of LLM::prompt(). It extends LLMException, so
 * code that only cares that the call failed catches that as before, while code
 * that wants to tell "a reducer refused this" from "the API call failed" catches
 * this instead:
 *
 *     try {
 *         $reply = LLM::use(AIModel\Claude::class)->prompt($prompt);
 *     } catch (PromptRejected $e) {
 *         Log::info("{$e->reducer()} refused the prompt: {$e->getMessage()}");
 *     }
 *
 * A reducer throws it with a reason and nothing else. The reducer's class name
 * is filled in by LLM::prompt() as the exception passes it, so a reducer never
 * has to name itself.
 */
class PromptRejected extends LLMException
{
    /**
     * Class name of the reducer that threw, once LLM::prompt() has recorded it.
     * @var string|null
     */
    private ?string $reducer = null;

    /**
     * The reducer that refused the prompt, or null if it was thrown outside the
     * reducer pipeline.
     */
    public function reducer(): ?string
    {
        return $this->reducer;
    }

    /**
     * Records which reducer threw this.
     *
     * Keeps the first name recorded, so a reducer that runs others inside itself
     * is reported as the one that refused rather than being overwritten.
     *
     * @internal called by LLM::prompt(); not part of the reducer-facing API
     * @return $this
     */
    public function from(string $reducer): static
    {
        $this->reducer ??= $reducer;
        return $this;
    }
}
