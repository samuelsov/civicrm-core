
{if !$count || $count == 1}
  {if $frequency_unit == 'day'}{ts}day{/ts}
  {elseif $frequency_unit == 'week'}{ts}week{/ts}
  {elseif $frequency_unit == 'month'}{ts}month{/ts}
  {elseif $frequency_unit == 'year'}{ts}year{/ts}
  {else}{$frequency_unit{/ts}
{else}
  {if $frequency_unit == 'day'}{ts}days{/ts}
  {elseif $frequency_unit == 'week'}{ts}weeks{/ts}
  {elseif $frequency_unit == 'month'}{ts}months{/ts}
  {elseif $frequency_unit == 'year'}{ts}years{/ts}
  {else}{$frequency_unit{/ts}
{/if}

