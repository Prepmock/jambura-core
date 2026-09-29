<?php
namespace Jambura\LLM;

/**
 * A pipeline step that prepares what the model will need.
 *
 * Registered with Pipeline::preprocessor(). Use one to gather or reshape: save
 * uploads, look up a customer, fetch documents, trim a long history.
 *
 * What belongs in the prompt goes into the prompt's own context sections, so
 * the model step never has to assemble text:
 *
 *     $context->prompt()->addContext('retrieved', $vendor->summary());
 *
 * The array it returns holds what the run needs but the model does not, such as
 * a record id to write back to later.
 */
interface Preprocessor
{
    /**
     * Prepares the run.
     *
     * @param Context $context the run's prompt, values and settings
     * @return array values to add to the Context, empty when the step only
     *               added to the prompt
     */
    public function process(Context $context): array;
}
