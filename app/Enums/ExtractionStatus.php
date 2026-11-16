<?php

namespace App\Enums;

enum ExtractionStatus: string
{
	/** The model finished (end_turn) and returned schema-valid JSON. */
	case Completed = 'completed';

	/** stop_reason refusal: the output is not read. */
	case Refused = 'refused';

	/** stop_reason max_tokens: the output may be incomplete, so it is not read. */
	case Truncated = 'truncated';

	/** API or transport error. */
	case Failed = 'failed';
}
