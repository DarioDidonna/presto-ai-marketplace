// npm audit per la CI, con eccezioni scritte e a scadenza (.github/npm-audit-exceptions.json).
// Fallisce per qualunque vulnerabilità nota, di qualsiasi gravità, tranne quelle in elenco: ogni eccezione ha un
// motivo e una data di scadenza, passata la quale la CI torna a fallire e l'eccezione va rivalutata.
// Serve quando una correzione non esiste ancora: spegnere il controllo nasconderebbe anche le vulnerabilità nuove.
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

const exceptions = JSON.parse(readFileSync(new URL('../.github/npm-audit-exceptions.json', import.meta.url), 'utf8'));
const today = new Date().toISOString().slice(0, 10);

let output;
try {
    output = execFileSync('npm', ['audit', '--json'], { encoding: 'utf8' });
} catch (error) {
    output = error.stdout; // npm audit esce con errore quando trova vulnerabilità: il JSON è comunque nello stdout
}

// Avvisi distinti (lo stesso avviso compare sotto ogni pacchetto che lo eredita)
const advisories = new Map();
for (const [name, vulnerability] of Object.entries(JSON.parse(output).vulnerabilities ?? {})) {
    for (const via of vulnerability.via) {
        if (typeof via !== 'object') continue;
        const id = via.url?.split('/').pop() ?? String(via.source);
        advisories.set(id, { id, title: via.title, severity: via.severity, packages: [...(advisories.get(id)?.packages ?? []), name] });
    }
}

let failed = false;
for (const advisory of advisories.values()) {
    const exception = exceptions.find((entry) => entry.id === advisory.id);
    const where = `${advisory.id} (${advisory.severity}) ${advisory.title} — ${advisory.packages.join(', ')}`;

    if (!exception) {
        console.error(`✗ ${where}`);
        failed = true;
    } else if (exception.until < today) {
        console.error(`✗ ${where}\n  eccezione scaduta il ${exception.until}: va rivalutata`);
        failed = true;
    } else {
        console.warn(`! ${where}\n  eccezione fino al ${exception.until}: ${exception.reason}`);
    }
}

// Eccezioni non più necessarie (vulnerabilità risolta): vanno tolte dall'elenco
for (const entry of exceptions.filter((entry) => !advisories.has(entry.id))) {
    console.error(`✗ eccezione ${entry.id} non più necessaria: toglila da .github/npm-audit-exceptions.json`);
    failed = true;
}

console.log(failed ? 'npm audit: da sistemare.' : `npm audit: ok (${advisories.size} avvisi, tutti con eccezione valida).`);
process.exit(failed ? 1 : 0);
