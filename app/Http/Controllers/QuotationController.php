<?php

namespace App\Http\Controllers;

use App\Models\Quotation;

class QuotationController extends Controller
{
    public function print(Quotation $quotation)
    {
        return view('quotations.print', ['quotation' => $quotation->load('lines', 'partner')]);
    }
}
