{extends "layout.tpl"}

{block name=title}Cuvinte favorite{/block}

{block name=content}
  <h3>Lista cuvintelor favorite pentru {$sUser->nick} ({$sUser->name})</h3>

  <dl class="favoriteDefs">
    {if $bookmarks}
      {foreach $bookmarks as $i => $row}
        <dt data-idx="{$i}">
          <strong class="count">{$i+1}.</strong>
          <a href="{$wwwRoot}definitie/{$row->definitionId}">{$row->lexicon}</a>
          adăugat la {$row->createDate|date_format:"%e %b %Y"}
          <a class="bookmarkRemoveButton" href="{$wwwRoot}ajax/bookmarkRemove.php?definitionId={$row->definitionId}">Șterge</a>
        </dt>
        <dd data-idx="{$i}">{$row->html}</dd>
      {/foreach}
    {else}
      Nu aveți niciun cuvânt favorit.
    {/if}
  </dl>
{/block}
