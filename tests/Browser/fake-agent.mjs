// Agente AI finto per i test nel browser: stesso contratto HTTP del servizio Python (ai-agent/), senza Ollama.
// Come l'agente vero cerca gli annunci con l'API interna di Laravel, quindi le schede mostrate sono annunci veri;
// la risposta però è fissa, così il test verifica l'interfaccia (chat, schede, carrello) e non il modello.
import { createServer } from 'node:http';
import { randomUUID } from 'node:crypto';

const PORT = Number(process.env.FAKE_AGENT_PORT ?? 8101);
const LARAVEL_URL = process.env.LARAVEL_URL ?? 'http://127.0.0.1:8100';
const TOKEN = process.env.AI_AGENT_TOKEN ?? '';

const json = (response, status, body) => {
    response.writeHead(status, { 'Content-Type': 'application/json' });
    response.end(JSON.stringify(body));
};

const readBody = async (request) => {
    let data = '';
    for await (const chunk of request) data += chunk;
    return data ? JSON.parse(data) : {};
};

createServer(async (request, response) => {
    if (request.method === 'GET' && request.url === '/health') {
        return json(response, 200, { status: 'ok', ollama: true, model_available: true });
    }

    if (request.headers.authorization !== `Bearer ${TOKEN}`) {
        return json(response, 401, { detail: 'Token non valido.' });
    }

    if (request.method === 'POST' && request.url === '/chat') {
        const { message = '', conversation_id: conversationId } = await readBody(request);
        // Parola cercata: l'ultima del messaggio ("Cerco uno zaino" → "zaino")
        const query = message.trim().split(/\s+/).pop() ?? '';
        const search = await fetch(`${LARAVEL_URL}/api/agent/articles?query=${encodeURIComponent(query)}&limit=3`, {
            headers: { Authorization: `Bearer ${TOKEN}`, Accept: 'application/json' },
        });
        const { articles = [] } = await search.json();

        return json(response, 200, {
            reply: articles.length ? `Ho trovato ${articles.length} annunci per "${query}".` : `Nessun annuncio per "${query}".`,
            conversation_id: conversationId ?? randomUUID(),
            articles: articles.map(({ id }) => ({ id })),
        });
    }

    return json(response, 404, { detail: 'Non trovato.' });
}).listen(PORT, '127.0.0.1', () => console.log(`Agente finto su http://127.0.0.1:${PORT}`));
