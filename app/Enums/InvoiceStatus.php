<?php

namespace App\Enums;

enum InvoiceStatus: string
{
	/** Uploaded, extraction not finished yet. */
	case Pending = 'pending';

	/** Extracted and every code check passed. */
	case Accepted = 'accepted';

	/** Extracted, but validation or an arithmetic/TRN check failed: a person must look. */
	case NeedsReview = 'needs_review';

	/** The document is not an invoice (quotation, receipt, statement...). */
	case Rejected = 'rejected';

	/** The model call failed, was refused or was cut off; nothing was extracted. */
	case Failed = 'failed';
}
