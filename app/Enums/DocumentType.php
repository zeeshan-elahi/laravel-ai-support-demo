<?php

namespace App\Enums;

enum DocumentType: string
{
	case Invoice = 'invoice';
	case NotInvoice = 'not_invoice';
}
