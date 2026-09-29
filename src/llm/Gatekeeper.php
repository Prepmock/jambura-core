<?php
namespace Jambura\LLM;

/**
 * A pipeline step that decides whether the rest of the route should run.
 *
 * Registered with Pipeline::gatekeeper(). Use one where a run can be pointless
 * or not allowed: an upload with no attachment, a user over quota, a request
 * without permission.
 *
 * Returning false stops the run, and Pipeline::feed() returns the Context with
 * wasStopped() true and stoppedAt() naming this step. Stopping is not an error,
 * so nothing is thrown; throw from the method itself when the caller should
 * handle a failure instead.
 */
interface Gatekeeper
{
    /**
     * Decides whether the run may carry on.
     *
     * @param Context $context the run's prompt, values and settings
     * @return bool|array false stops the run, true carries on, and an array
     *                   carries on and adds those values to the Context
     */
    public function allows(Context $context);
}
