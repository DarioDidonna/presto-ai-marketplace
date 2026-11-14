"""Fixture comuni: Laravel finto (httpx.MockTransport) e modello finto che segue un copione."""

import json
from collections.abc import Iterator

import httpx
import pytest
from langchain_core.language_models.fake_chat_models import GenericFakeChatModel
from langchain_core.messages import AIMessage

from presto_ai_agent.config import Settings
from presto_ai_agent.laravel_client import LaravelClient

CATEGORIES = [
    {"id": 3, "name": "Animali domestici", "published_count": 2},
    {"id": 5, "name": "Elettronica", "published_count": 4},
    {"id": 10, "name": "Sport", "published_count": 3},
]

BIKE = {
    "id": 32,
    "title": "Bicicletta da corsa in alluminio",
    "price": 480.0,
    "category": "Sport",
    "description": "Telaio in alluminio taglia 54",
    "url": "http://localhost:8000/annunci/32",
    "image_url": "http://localhost:8000/storage/articles/32/crop_300x300_a.jpg",
    "published_at": "2026-10-01",
}


class FakeLaravel:
    """Registra le richieste ricevute e risponde come le API /api/agent/* di Laravel."""

    def __init__(self, articles=None, down=False):
        self.articles = [BIKE] if articles is None else articles
        self.down = down
        self.requests: list[httpx.Request] = []

    def __call__(self, request: httpx.Request) -> httpx.Response:
        self.requests.append(request)
        if self.down:
            raise httpx.ConnectError("Laravel spento")
        path = request.url.path
        if path.endswith("/categories"):
            return httpx.Response(200, json={"categories": CATEGORIES})
        if path.endswith("/articles"):
            return httpx.Response(200, json={"total": len(self.articles), "filters": {}, "articles": self.articles})
        article_id = int(path.rsplit("/", 1)[1])
        match = next((a for a in self.articles if a["id"] == article_id), None)
        if match is None:
            return httpx.Response(404, json={"message": "Not Found"})
        return httpx.Response(200, json={"article": {**match, "seller": "Sara Colombo", "images": []}})

    @property
    def last_params(self) -> dict:
        return dict(self.requests[-1].url.params)


@pytest.fixture
def settings() -> Settings:
    return Settings(laravel_url="http://laravel.test", api_token="segreto", model="finto")


@pytest.fixture
def laravel() -> FakeLaravel:
    return FakeLaravel()


@pytest.fixture
def client(settings, laravel) -> LaravelClient:
    return LaravelClient(settings, transport=httpx.MockTransport(laravel))


class ScriptedModel(GenericFakeChatModel):
    """Modello finto: restituisce le risposte del copione in ordine e accetta i tool (bind_tools)."""

    def bind_tools(self, tools, **kwargs):
        return self


def scripted(*steps: AIMessage) -> ScriptedModel:
    iterator: Iterator[AIMessage] = iter(steps)
    return ScriptedModel(messages=iterator)


def tool_call(name: str, call_id: str = "call_1", **args) -> AIMessage:
    return AIMessage(content="", tool_calls=[{"name": name, "args": args, "id": call_id}])


def dumps(data) -> str:
    return json.dumps(data, ensure_ascii=False)
