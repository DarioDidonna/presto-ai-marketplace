# Agente AI (FastAPI + LangGraph) e rilevatore dei volti (YuNet, incluso nel pacchetto: nessun download)
FROM ghcr.io/astral-sh/uv:0.13.8-python3.12-trixie-slim

WORKDIR /app
ENV UV_COMPILE_BYTECODE=1 UV_LINK_MODE=copy UV_NO_DEV=1

# Dipendenze prima del codice, dalle versioni esatte di uv.lock
COPY pyproject.toml uv.lock README.md ./
RUN uv sync --locked --no-install-project

COPY src ./src
RUN uv sync --locked

RUN useradd --system --no-create-home agent
USER agent

ENV PATH="/app/.venv/bin:$PATH" PRESTO_AI_HOST=0.0.0.0 PRESTO_AI_PORT=8001
EXPOSE 8001
CMD ["presto-ai-agent"]
