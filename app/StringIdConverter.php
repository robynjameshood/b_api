<?php

namespace App;


class StringIdConverter
{
    public function stringToId($type, $string)
    {
        $returnValue = null;
        switch ($type) {
            case 'cover_type':
                switch (strtoupper($string)) {
                    case 'PERSONAL':
                        $returnValue = 2;
                        break;
                    case 'VEHICLE':
                        $returnValue = 1;
                        break;
                    default:
                        //Log to rollbar

                        break;
                }
                break;
            case 'cover_range':
                switch (strtoupper($string)) {
                    case 'ALL':
                        $returnValue = -1;
                        break;
                    case 'LOCAL':
                        $returnValue = 1;
                        break;
                    case 'NATIONAL':
                        $returnValue = 2;
                        break;
                    case 'EUROPEAN':
                        $returnValue = 3;
                        break;
                    case 'RMC COMPREHENSIVE':
                        $returnValue = 4;
                        break;
                    default:
                        //Log to rollbar

                        break;
                }
                break;
            case 'vehicle_type':
                switch (strtoupper($string)) {
                    case 'ALL':
                        $returnValue = -1;
                        break;
                    case 'CAR':
                        $returnValue = 1;
                        break;
                    case 'VAN':
                        $returnValue = 2;
                        break;
                    case 'BIKE':
                        $returnValue = 3;
                        break;
                    default:
                        //Log to rollbar

                        break;
                }
                break;
            default:
                //Log to rollbar

                break;
        }
        return $returnValue;
    }
}
