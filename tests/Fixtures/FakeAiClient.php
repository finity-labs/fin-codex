<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Tests\Fixtures;

use Closure;
use FinityLabs\LinCodex\Ai\AiCallFailed;
use FinityLabs\LinCodex\Ai\AiReason;
use FinityLabs\LinCodex\Ai\Contracts\AiClient;
use FinityLabs\LinCodex\Ai\StructuredCompletion;
use FinityLabs\LinCodex\Ai\StructuredRequest;
use LogicException;
use Throwable;

/**
 * The AI seam fin-codex tests bind instead of the real client.
 *
 * `app()->instance(AiClient::class, $fake)` - what `finCodexFakeAi()` does -
 * is the whole wiring: the availability rule, the translator, the settings
 * page, the tab action and the install step each resolve `AiClient` from the
 * container at call time, so one bind answers for all of them.
 *
 * Nothing here names a symbol from the optional laravel/ai SDK, which is why
 * every AI test runs on every fin-codex CI row. The real round trip through
 * the SDK belongs to lin-codex's suite and is covered there.
 *
 * Configure the answers through the constructor - `installed`, `connection`,
 * `tiers` - and queue the structured ones with `translating()`, `push()` or
 * `answering()`. Read `$requests` and `$connectionTests` afterwards to see
 * what the code under test actually asked for.
 */
final class FakeAiClient implements AiClient
{
    /** @var list<StructuredRequest> */
    public array $requests = [];

    /** @var list<array{provider: string, model: string|null, apiKey: string|null}> */
    public array $connectionTests = [];

    /** @var list<StructuredCompletion|Throwable> */
    private array $queue = [];

    private ?Closure $answer = null;

    /**
     * @param  bool  $installed  what installed() reports, and with it the sdk_missing availability state
     * @param  array<string, string>  $labels  provider value => label, what providers() offers while installed
     * @param  string|null  $connection  what testConnection() answers: null for a working connection, else an AiReason key
     * @param  array{default: string, cheapest: string, smartest: string}|null  $tiers  null: the seam cannot list tier models, so tierModels() fails
     */
    public function __construct(
        public bool $installed = true,
        private array $labels = ['anthropic' => 'Anthropic', 'openai' => 'OpenAI', 'ollama' => 'Ollama'],
        public ?string $connection = null,
        public ?array $tiers = [
            'default' => 'fake-default',
            'cheapest' => 'fake-cheapest',
            'smartest' => 'fake-smartest',
        ],
    ) {}

    /**
     * A fake that answers one structured call with these fields.
     *
     * Named for what fin-codex does above the seam: every structured call
     * this package makes is a translation.
     *
     * @param  array<string, mixed>  $fields
     */
    public static function translating(array $fields, int $promptTokens = 0, int $completionTokens = 0, bool $truncated = false): self
    {
        return (new self)->push(new StructuredCompletion($fields, $promptTokens, $completionTokens, $truncated));
    }

    /** Queue one more answer, or one failure to throw. */
    public function push(StructuredCompletion|Throwable $next): self
    {
        $this->queue[] = $next;

        return $this;
    }

    /** Answer every call from a closure instead of the queue. */
    public function answering(Closure $answer): self
    {
        $this->answer = $answer;

        return $this;
    }

    public function installed(): bool
    {
        return $this->installed;
    }

    /**
     * @return array<string, string>
     */
    public function providers(): array
    {
        return $this->installed ? $this->labels : [];
    }

    /**
     * @throws AiCallFailed when the fake was built with no tier list
     *
     * @return array{default: string, cheapest: string, smartest: string}
     */
    public function tierModels(string $provider): array
    {
        return $this->tiers ?? throw new AiCallFailed(AiReason::UNAVAILABLE);
    }

    public function structured(StructuredRequest $request): StructuredCompletion
    {
        $this->requests[] = $request;

        if ($this->answer instanceof Closure) {
            return ($this->answer)($request);
        }

        if ($this->queue === []) {
            throw new LogicException('FakeAiClient has no completion queued for: '.$request->prompt);
        }

        $next = array_shift($this->queue);

        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }

    public function testConnection(string $provider, ?string $model, ?string $apiKey): ?string
    {
        $this->connectionTests[] = ['provider' => $provider, 'model' => $model, 'apiKey' => $apiKey];

        return $this->connection;
    }
}
