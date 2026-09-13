<?php
namespace AIModel;

use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use Jambura\LLM;
use Jambura\LLM\LLMException;
use Jambura\LLM\Prompt;

/**
 * Claude Messages API adapter.
 *
 * Sends the role as the system prompt, and the context, instructions and task
 * as XML sections in the user message, task last. Calls Claude through the
 * official SDK (composer require anthropic-ai/sdk), which reads
 * ANTHROPIC_API_KEY unless setClient() is given a configured client.
 *
 * A request Claude declines is retried server-side on Anthropic's default
 * fallback model. A refusal that survives the fallback throws.
 */
class Claude extends LLM
{
    const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    protected string $model = 'claude-opus-5';

    /**
     * Cap on the reply length, in tokens.
     * @var int
     */
    private int $maxTokens = 16000;

    /**
     * @var Client|null
     */
    private ?Client $client = null;

    public function setClient(Client $client): static
    {
        $this->client = $client;
        return $this;
    }

    public function setMaxTokens(int $maxTokens): static
    {
        $this->maxTokens = $maxTokens;
        return $this;
    }

    public function serialize(Prompt $prompt): array
    {
        $payload = ['model' => $this->model, 'max_tokens' => $this->maxTokens];
        if ($prompt->getRole() !== null) {
            $payload['system'] = $prompt->getRole();
        }
        $payload['messages'] = [['role' => 'user', 'content' => $this->toXml($prompt)]];
        return $payload;
    }

    protected function send(array $payload): string
    {
        try {
            $message = $this->client()->beta->messages->create(
                maxTokens: $payload['max_tokens'],
                messages: $payload['messages'],
                model: $payload['model'],
                system: $payload['system'] ?? null,
                fallbacks: 'default',
                betas: [self::FALLBACK_BETA],
            );
        } catch (AnthropicException $e) {
            throw new LLMException('Claude request failed: ' . $e->getMessage(), 0, $e);
        }

        // A refusal can arrive with no content, so check before reading it.
        if ($message->stopReason === 'refusal') {
            throw new LLMException('Claude declined the request');
        }

        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }
        return $text;
    }

    /**
     * Renders the context, instructions and task as XML, task last.
     */
    private function toXml(Prompt $prompt): string
    {
        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('  ');

        if ($prompt->getContext()) {
            $xml->startElement('context');
            foreach ($prompt->getContext() as $section => $items) {
                $xml->startElement($section);
                foreach ($items as $item) {
                    $xml->writeElement('item', $item);
                }
                $xml->endElement();
            }
            $xml->endElement();
        }

        if ($prompt->getInstructions()) {
            $xml->startElement('instructions');
            foreach ($prompt->getInstructions() as $instruction) {
                $xml->writeElement('instruction', $instruction);
            }
            $xml->endElement();
        }

        $xml->writeElement('task', $prompt->getTask());

        return rtrim($xml->outputMemory());
    }

    private function client(): Client
    {
        if ($this->client === null) {
            if (!class_exists(Client::class)) {
                throw new LLMException('The Claude adapter needs the Anthropic SDK: composer require anthropic-ai/sdk');
            }
            $this->client = new Client();
        }
        return $this->client;
    }
}
