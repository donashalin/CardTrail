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
        $old = $mapping->name;
        $mapping->update($request->validate(['name' => ['required', 'string', 'max:100']]));

        activity('settings')
            ->causedBy($request->user())
            ->performedOn($mapping)
            ->event('column_mapping_renamed')
            ->log("Renamed column match “{$old}” to “{$mapping->name}”");

        return back()->with('success', 'Column match renamed.');
    }

    public function destroy(Request $request, ColumnMapping $mapping): RedirectResponse
    {
        $mapping->delete();

        activity('settings')
            ->causedBy($request->user())
            ->event('column_mapping_deleted')
            ->withProperties(['name' => $mapping->name, 'header_signature' => $mapping->header_signature])
            ->log("Forgot column match “{$mapping->name}”");

        return back()->with('success', "Forgot “{$mapping->name}”. The next file with those headers will ask for its columns again.");
    }
}
