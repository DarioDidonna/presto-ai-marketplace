<?php

namespace Tests\Feature;

use App\Models\User;
use App\View\Composers\FooterStatusComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FooterStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(FooterStatusComposer::CACHE_KEY);
    }

    // Valore mostrato accanto a un servizio nel riquadro "Stato dei servizi"
    private function value(string $html, string $service): string
    {
        preg_match('#'.preg_quote(__('ui.footer_service_'.$service), '#').'</span>\s*<span[^>]*>([^<]*)</span>#', $html, $match);

        return trim($match[1] ?? '');
    }

    private function enableAgent(): void
    {
        config(['services.ai_agent.url' => 'http://agent.test', 'services.ai_agent.token' => 'segreto']);
    }

    public function test_services_not_configured(): void
    {
        Http::fake();

        $this->get('/')
            ->assertSee(__('ui.footer_status_title'))
            ->assertSee(__('ui.footer_status_partial', ['on' => 0, 'total' => 4]))
            ->assertSee(__('ui.footer_value_disabled'))
            ->assertSee(trans_choice('ui.footer_value_online', 0));

        Http::assertNothingSent();
    }

    public function test_agent_running_with_its_model(): void
    {
        $this->enableAgent();
        Http::fake(['agent.test/health' => Http::response(['status' => 'ok', 'model_available' => true])]);

        $this->get('/')->assertDontSee('qwen')->assertSee(__('ui.footer_value_on'));
    }

    public function test_agent_running_without_the_chat_model_still_hides_faces(): void
    {
        $this->enableAgent();
        Http::fake(['agent.test/health' => Http::response(['status' => 'degraded', 'model_available' => false])]);

        $html = $this->get('/')->getContent();
        $this->assertSame(__('ui.footer_value_on'), $this->value($html, 'faces'));
        $this->assertSame(__('ui.footer_value_off'), $this->value($html, 'assistant'));
    }

    public function test_agent_down_and_result_cached_for_a_minute(): void
    {
        $this->enableAgent();
        $calls = 0;
        Http::fake(['agent.test/health' => function () use (&$calls) {
            $calls++;
            throw new ConnectionException('rifiutata');
        }]);

        $html = $this->get('/')->getContent(); // prima la pagina: imposta la lingua usata da __()
        $this->assertSame(__('ui.footer_value_off'), $this->value($html, 'faces'));
        $this->get('/annunci');

        $this->assertSame(1, $calls); // il secondo caricamento usa la cache: il footer non rallenta le pagine
    }

    public function test_reviewers_online_are_counted_from_active_sessions(): void
    {
        config(['session.driver' => 'database']);
        $revisor = User::factory()->revisor()->create();
        $other = User::factory()->revisor()->create();
        $buyer = User::factory()->create();
        foreach ([[$revisor, now()], [$buyer, now()], [$other, now()->subHour()]] as $i => [$user, $at]) {
            DB::table('sessions')->insert([
                'id' => "s$i", 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'test',
                'payload' => '', 'last_activity' => $at->getTimestamp(),
            ]);
        }

        $this->get('/')->assertSee(trans_choice('ui.footer_value_online', 1, ['count' => 1]));
    }

    public function test_realtime_shows_whether_reverb_is_listening(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.options' => ['host' => '127.0.0.1', 'port' => $port, 'scheme' => 'http', 'useTLS' => false],
        ]);

        $this->assertSame('Reverb', $this->value($this->get('/')->getContent(), 'realtime'));

        fclose($server);
        Cache::forget(FooterStatusComposer::CACHE_KEY);
        $html = $this->get('/')->getContent();
        $this->assertSame(__('ui.footer_value_off'), $this->value($html, 'realtime'));
    }
}
