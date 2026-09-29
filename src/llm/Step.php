<?php
namespace Jambura\LLM;

/**
 * A pipeline step with no special meaning to the pipeline.
 *
 * Registered with Pipeline::step(), for work that is neither a gate, a
 * preparation nor a model call: persisting a result, notifying someone,
 * recording what a run cost.
 *
 * Returning an array adds those values to the Context. Any other return value
 * is ignored, so a step that only has a side effect can return null.
 */
interface Step
{
    /**
     * Does the step's work.
     *
     * @param Context $context the run's prompt, values and settings
     * @return mixed an array to add to the Context, or anything to ignore
     */
    public function handle(Context $context): mixed;
}
