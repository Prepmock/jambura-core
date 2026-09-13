<?php
namespace AIModel;

use Jambura\LLM;
use Jambura\LLM\LLMException;
use Jambura\LLM\Prompt;

/**
 * Deepseek chat completions adapter.
 *
 * Sends the role as the system message, and the context, instructions and task
 * as a JSON document in the user message, task last. Reads the API key from
 * DEEPSEEK_API_KEY unless setApiKey() is called. Needs the curl extension.
 */
class Deepseek extends LLM
{
    const ENDPOINT = 'https://api.deepseek.com/chat/completions';

    protected string $model = 'deepseek-v4-pro';

    /**
     * @var string|null
     */
    private ?string $apiKey = null;

    /**
     * Seconds to wait for the whole request.
     * @var int
     */
    private int $timeout = 300;

    public function setApiKey(string $apiKey): static
    {
        $this->apiKey = $apiKey;
        return $this;
    }

    public function setTimeout(int $seconds): static
    {
        $this->timeout = $seconds;
        return $this;
    }

    public function serialize(Prompt $prompt): array
    {
        $messages = [];
        if ($prompt->getRole() !== null) {
            $messages[] = ['role' => 'system', 'content' => $prompt->getRole()];
        }

        $document = [];
        if ($prompt->getContext()) {
            $document['context'] = $prompt->getContext();
        }
        if ($prompt->getInstructions()) {
            $document['instructions'] = $prompt->getInstructions();
        }
        $document['task'] = $prompt->getTask();

        $messages[] = [
            'role' => 'user',
            'content' => json_encode(
                $document,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ),
        ];

        return ['model' => $this->model, 'messages' => $messages];
    }

    protected function send(array $payload): string
    {
        $apiKey = $this->apiKey ?? (string) getenv('DEEPSEEK_API_KEY');
        if ($apiKey === '') {
            throw new LLMException('No Deepseek API key. Set DEEPSEEK_API_KEY or call setApiKey()');
        }
        if (!function_exists('curl_init')) {
            throw new LLMException('The Deepseek adapter needs the curl extension');
        }

        $curl = curl_init(self::ENDPOINT);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
        $body = curl_exec($curl);
        if ($body === false) {
            throw new LLMException('Deepseek request failed: ' . curl_error($curl));
        }
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        $response = json_decode($body, true);
        if ($status >= 400) {
            throw new LLMException("Deepseek returned HTTP $status: " . ($response['error']['message'] ?? $body));
        }

        $choice = $response['choices'][0] ?? null;
        if (($choice['finish_reason'] ?? null) === 'content_filter') {
            throw new LLMException('Deepseek withheld the reply: content_filter');
        }
        $content = $choice['message']['content'] ?? null;
        if (!is_string($content)) {
            throw new LLMException('Deepseek reply had no message content');
        }
        return $content;
    }
}
