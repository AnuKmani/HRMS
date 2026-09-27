<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Row-level visibility
    |--------------------------------------------------------------------------
    |
    | A `*.view` permission answers one question: may this role open the
    | module at all? It says nothing about *which rows*. The roles listed
    | below are given a narrower answer — they only ever see the rows
    | attached to what they personally run.
    |
    | Everything not listed reads every row its permission already admits.
    | That direction is deliberate: an unlisted custom role falls back to
    | the plain meaning of the permission instead of silently seeing
    | nothing, which would be a confusing failure with no obvious cause.
    |
    | Policies and the list queries both read these two lists, so narrowing
    | a role here narrows the collection *and* the single-record check at
    | the same time. A policy that checked only the individual record while
    | the index returned everything would be no boundary at all.
    |
    */

    'visibility' => [

        /*
        | Only projects/sites they personally manage or supervise.
        |
        | Project Manager is deliberately absent: it holds both
        | `projects.manage` and `sites.manage`, i.e. it owns those modules
        | and can see all of them. Its narrowing is on employees instead.
        */
        'project' => ['Site Supervisor', 'Site Engineer'],

        /*
        | Only employees attached to the projects/sites they run — plus
        | their own record, which is never gated by scope.
        |
        | Project Manager is the reason this list exists: they may open
        | every project but only the workforce behind the ones they manage,
        | and never a colleague's salary.
        */
        'employee' => ['Project Manager', 'Site Supervisor', 'Site Engineer'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Geofence bounds
    |--------------------------------------------------------------------------
    |
    | Validation limits for a site's radius, in metres. The radius itself
    | lives on the site row and is read from there every time a check-in is
    | judged — these bounds only decide what may be written in the first
    | place, so tightening them is a config change rather than a code change
    | when a deployment needs a different ceiling.
    |
    | Nothing anywhere hard-codes a site's coordinates: they are columns.
    |
    */

    'geofence' => [
        'min_radius_metres' => (float) env('GEOFENCE_MIN_RADIUS_METRES', 10),
        'max_radius_metres' => (float) env('GEOFENCE_MAX_RADIUS_METRES', 10000),
    ],

];
