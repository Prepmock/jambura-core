<?php
namespace Jambura\LLM;

/**
 * Keeps only the context sections a kind of prompt has a use for.
 *
 * The simplest useful reducer, and the one that proves the pipeline. It is given
 * a map of prompt type to the sections that type needs, and empties the rest:
 *
 *     LLM::registerReducers([
 *         new ContextFilter([
 *             'receipt'        => ['static', 'retrieved'],
 *             'voyage-summary' => ['retrieved', 'dynamic'],
 *             'greeting'       => [],
 *         ]),
 *     ]);
 *
 * A 'receipt' prompt then reaches the model with its static and retrieved
 * context and without the conversation history it was never going to use, and a
 * 'greeting' prompt reaches it with no context at all. Types the map does not
 * name are left alone, so adding a type to an application does not mean having
 * to add it here first.
 *
 * The map is checked when the filter is built, which is normally registration
 * time, so a mistyped section name fails at bootstrap rather than on the first
 * prompt of that type.
 */
class ContextFilter implements Reducer
{
    /**
     * Sections to keep, keyed by prompt type.
     * @var array<string, string[]>
     */
    private array $keep;

    /**
     * @param array<string, string[]> $keep prompt type => the context sections
     *                                      that type keeps, from
     *                                      Prompt::CONTEXT_SECTIONS. An empty
     *                                      list drops every section.
     *
     * @throws LLMException if a type name is blank, or a section is not one of
     *                      Prompt::CONTEXT_SECTIONS
     */
    public function __construct(array $keep)
    {
        foreach ($keep as $type => $sections) {
            if (!is_string($type) || trim($type) === '') {
                throw new LLMException(self::class . ' is keyed by prompt type, so each key must be a non-empty string');
            }
            if (!is_array($sections)) {
                throw new LLMException("The sections kept for '$type' must be an array of context section names");
            }
            $unknown = array_diff($sections, Prompt::CONTEXT_SECTIONS);
            if ($unknown) {
                throw new LLMException(
                    "Unknown context section for '$type': " . implode(', ', $unknown)
                    . '. Use one of: ' . implode(', ', Prompt::CONTEXT_SECTIONS)
                );
            }
        }
        $this->keep = $keep;
    }

    /**
     * The types the map names, so the pipeline runs this filter for those alone.
     *
     * @return string[]
     */
    public function types(): array
    {
        return array_keys($this->keep);
    }

    public function reduce(Prompt $prompt): Prompt
    {
        // The pipeline routes by types() already; this also covers a filter
        // called directly, in a test or from another reducer.
        $keep = $this->keep[$prompt->getType()] ?? null;
        if ($keep === null) {
            return $prompt;
        }

        $drop = array_diff(Prompt::CONTEXT_SECTIONS, $keep);

        return $drop ? $prompt->removeContext(...$drop) : $prompt;
    }
}
