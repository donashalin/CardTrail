<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ColumnMapping;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Saved column matches are applied automatically to files with the same headers; admins can rename or forget them. */
class ColumnMappingController extends Controller
{
    public function update(Request $request, ColumnMapping $mapping): RedirectResponse
    {
        $mapping->update($request->validate(['name' => ['required', 'string', 'max:100']]));

        return back()->with('success', 'Column match renamed.');
    }

    public function destroy(ColumnMapping $mapping): RedirectResponse
    {
        $mapping->delete();

        return back()->with('success', "Forgot “{$mapping->name}”. The next file with those headers will ask for its columns again.");
    }
}
