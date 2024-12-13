<?php


namespace App;


use Illuminate\Database\Eloquent\Model;

class SchemeConfig extends Model
{
    const TYPES = [
        123 => 'NewBusiness',
        127 => 'MTA',
        120 => 'Renewal',
    ];

    public static function schemeConfigDocumentDescription($insurer, $scheme, $documentName)
    {
        $filter = function($q) use ($documentName)
        {
            $q->where('name', $documentName);
        };
        $config = static::where('insurer', '=', $insurer)
            ->where('scheme', '=', $scheme)
            ->where('type', '=', SchemeConfig::TYPES[123] )
            ->with(
                [
                    'documentTypes' => $filter
                ]
            )
            ->whereHas('documentTypes',$filter)
            ->first();

        return is_null($config) ? $config : $config->documentTypes->first()->pivot->description;
    }
}
