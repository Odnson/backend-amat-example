<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PageContent;
use Illuminate\Http\Request;

class PageContentController extends Controller
{
    public function show($slug)
    {
        $validSlugs = array_keys(PageContent::getAvailableSlugs());
        
        if (!in_array($slug, $validSlugs)) {
            return response()->json([
                'message' => 'Page not found'
            ], 404);
        }

        $page = PageContent::getBySlug($slug);

        if (!$page) {
            return response()->json([
                'slug' => $slug,
                'title' => PageContent::getAvailableSlugs()[$slug] ?? $slug,
                'content' => null,
                'is_published' => false
            ]);
        }

        return response()->json([
            'slug' => $page->slug,
            'title' => $page->title,
            'content' => $page->content,
            'meta_description' => $page->meta_description,
            'is_published' => $page->is_published,
            'updated_at' => $page->updated_at
        ]);
    }

    public function index()
    {
        $pages = PageContent::all();
        $availableSlugs = PageContent::getAvailableSlugs();

        $result = [];
        foreach ($availableSlugs as $slug => $title) {
            $page = $pages->firstWhere('slug', $slug);
            $result[] = [
                'slug' => $slug,
                'title' => $page ? $page->title : $title,
                'is_published' => $page ? $page->is_published : false,
                'updated_at' => $page ? $page->updated_at : null
            ];
        }

        return response()->json($result);
    }
}
