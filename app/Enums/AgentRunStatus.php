<?php

namespace App\Enums;

enum AgentRunStatus: string
{
	case Running = 'running';
	case Completed = 'completed';
	case Refused = 'refused';
	case Truncated = 'truncated';
	case MaxTurns = 'max_turns';
	case Failed = 'failed';
}
