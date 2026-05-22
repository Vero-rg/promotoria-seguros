<?php

namespace App\Http\Controllers;

use App\Models\Promoter;
use App\Models\Agent;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PromoterController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->query('search');
        $type = $request->query('type');
        $date = $request->query('date');

        $promoters = collect();
        $agents = collect();

        $promoterIdSearch = null;
        $agentIdSearch = null;

        // Detectar si la búsqueda incluye la letra P o A seguida de un número
        if ($search) {
            if (preg_match('/^[pP](\d+)$/', $search, $matches)) {
                $promoterIdSearch = $matches[1];
            } elseif (preg_match('/^[aA](\d+)$/', $search, $matches)) {
                $agentIdSearch = $matches[1];
            }
        }

        if (!$type || $type === 'promoter') {
            $promoters = Promoter::with('agents')
                ->when($search, function ($query, $search) use ($promoterIdSearch) {
                    $query->where(function ($q) use ($search, $promoterIdSearch) {
                        $q->where('name', 'like', "%{$search}%")
                          ;
                        if ($promoterIdSearch) {
                            $q->orWhere('id', $promoterIdSearch);
                        } elseif (is_numeric($search)) {
                            $q->orWhere('id', $search);
                        }
                    });
                })
                ->when($date, function ($query, $date) {
                    $query->whereDate('created_at', $date);
                })
                ->latest()
                ->get()
                ->map(function ($item) {
                    $item->type = 'promoter';
                    $item->uid = 'promoter_' . $item->id;
                    $item->display_id = 'P' . $item->id;
                    return $item;
                });
        }

        if (!$type || $type === 'agent') {
            $agents = Agent::with('promoter')
                ->when($search, function ($query, $search) use ($agentIdSearch) {
                    $query->where(function ($q) use ($search, $agentIdSearch) {
                        $q->where('name', 'like', "%{$search}%")
                          ;
                        if ($agentIdSearch) {
                            $q->orWhere('id', $agentIdSearch);
                        } elseif (is_numeric($search)) {
                            $q->orWhere('id', $search);
                        }
                    });
                })
                ->when($date, function ($query, $date) {
                    $query->whereDate('created_at', $date);
                })
                ->latest()
                ->get()
                ->map(function ($item) {
                    $item->type = 'agent';
                    $item->uid = 'agent_' . $item->id;
                    $item->display_id = 'A' . $item->id;
                    return $item;
                });
        }

        // Unimos las colecciones, las ordenamos por fecha de creación y reseteamos las llaves
        $directory = $promoters->concat($agents)->sortByDesc('created_at')->values();

        return Inertia::render('Directory/Index', [
            'directory' => $directory,
            'filters' => [
                'search' => $search,
                'type' => $type,
                'date' => $date,
            ]
        ]);
    }

    public function create()
    {
        return Inertia::render('Directory/Create', [
            'promoters' => Promoter::orderBy('name')->get()
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        Promoter::create($validated);
        
        return redirect()->route('directorio')->with('success', 'Promotor registrado correctamente.');
    }

    public function show(Promoter $promoter)
    {
        $promoter->load('agents');
        return Inertia::render('Directory/Show', [
            'entity' => $promoter,
            'type' => 'promoter'
        ]);
    }

    public function edit(Promoter $promoter)
    {
        return Inertia::render('Directory/Edit', [
            'entity' => $promoter,
            'type' => 'promoter',
            'promoters' => Promoter::orderBy('name')->get()
        ]);
    }

    public function update(Request $request, Promoter $promoter)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
        ]);

        $promoter->update($validated);
        return redirect()->route('directorio')->with('success', 'Promotor actualizado correctamente.');
    }

    public function destroy(Promoter $promoter)
    {
        $promoter->delete();
        return redirect()->route('directorio')->with('success', 'Promotor eliminado correctamente.');
    }
}