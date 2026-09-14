<?php
namespace Jambura\LLM;

/**
 * Formats Jambura\LLM::handlePrompt() can render a prompt in.
 */
enum Format
{
    case Xml;
    case Json;
    case Text;
}
