<?php

namespace App\Http\Controllers;

use App\Models\Article;
use Illuminate\Http\Request;

class PublicController extends Controller
{
    public function homepage()
    {
        $articles = Article::accepted()->withCardRelations()->latest()->take(6)->get();

        return view('homepage', compact('articles'));
    }

    // Vecchio indirizzo della ricerca: la ricerca ora vive nell'elenco annunci, insieme ai filtri

    public function setLanguage(Request $request, string $lang)
    {
        session()->put('locale', $lang);
        // Salvata anche sull'utente: serve per le email e per ripristinarla al prossimo login
        $request->user()?->forceFill(['locale' => $lang])->save();

        return redirect()->back();
    }
}
