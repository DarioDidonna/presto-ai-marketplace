<?php

namespace Tests\Feature;

use App\Services\AgentUnavailableException;
use App\Services\AiAgentClient;
use Tests\TestCase;

class AiAgentClientTest extends TestCase
{
    public function test_an_unreachable_agent_fails_fast_instead_of_holding_the_page(): void
    {
        // Indirizzo non instradabile: la connessione resta appesa finché non scade il connectTimeout.
        // Senza, vale quello predefinito di Laravel: la chat dell'utente resta ferma 10 secondi (misurato).
        config(['services.ai_agent.url' => 'http://10.255.255.1:8001', 'services.ai_agent.token' => 'segreto']);

        $start = microtime(true);
        try {
            app(AiAgentClient::class)->chat('Cerco una bici', null, 'it');
            $this->fail('Nessun errore con l\'agente irraggiungibile.');
        } catch (AgentUnavailableException) {
            $this->assertLessThan(5, microtime(true) - $start);
        }
    }
}
