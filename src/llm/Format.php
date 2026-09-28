<?php
namespace Jambura\LLM;

/**
 * Formats Jambura\LLM::handlePrompt() can render a prompt in.
 *
 * PHP 7 has no enums, so these are constants. An adapter's $format is checked
 * against ALL when the adapter is registered.
 */
final class Format
{
    const XML = 'xml';
    const JSON = 'json';
    const TEXT = 'text';

    /**
     * Every format, for validation.
     */
    const ALL = [self::XML, self::JSON, self::TEXT];

    private function __construct()
    {
    }
}
