<?php

namespace App\Console\Commands;

use App\ElectraOnePageQuote;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\BarbaraWebServices;

class sendElectraQuoteReport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'send:electra:quote:report';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sends a report of all quotes generated through electra for yesterday';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {

        $barbaraWebServices = new BarbaraWebServices();
        $today = Carbon::today();
        $yesterday = Carbon::yesterday();
        $rows = "<tr><td>There have been no MTA quotes logged for the above date.</td></tr>";
        $quotes = ElectraOnePageQuote::where('updated_at', '<=' , $today)
            ->where('updated_at', '>', $yesterday)
            ->get();
        if($quotes->count() > 0) {
            $rows = [];
            $rows[] = <<<EOS
            <tr>
                <th>Policy Number</th>
                <th>Effective Date</th>
                <th>Basic Addition / Return Premium</th>
                <th>Commission</th>
                <th>Annual Difference</th>
                <th>Broker's Admin Charge / Discount</th>
            </tr>
EOS;
            foreach ($quotes as $quote) {

                $rowText = "<tr>";
                $rowText .= "<td>" . $quote['policy_number'] . "</td>";
                $rowText .= "<td>" . $quote['effective_date'] . "</td>";
                $rowText .= "<td>" . number_format(floatval($quote['aprp']), 2) . "</td>";
                $rowText .= "<td>" . number_format(floatval($quote['commission']), 2) . "</td>";
                $rowText .= "<td>" . number_format(floatval($quote['annual_difference']), 2) . "</td>";
                $rowText .= "<td>" . number_format(floatval($quote['admin_charge_discount']), 2) . "</td>";
                $rowText .= "</tr>";
                $rows[] = $rowText;
            }
            $rows = implode("", $rows);
        }

        $barbaraResponse = $barbaraWebServices->sendElectraOnePageReport($yesterday->format('d/m/Y'), $rows);

        if ($barbaraResponse['status']) {
            Log::error('Failed to send electra quote report', ['result' => $barbaraResponse]);
        }

        return 1;
    }
}
