<?php
User::mustHave(User::PRIV_WOTD);

$id = Request::get('id');
$deleteId = Request::get('deleteId');
$saveButton = Request::has('saveButton');
$artist = $id
        ? WotdArtist::get_by_id($id)
        : Model::factory('WotdArtist')->create();

if ($deleteId) {
  WotdArtist::delete_all_by_id($deleteId);
  Log::info("Deleted author {$deleteId}");
  FlashMessage::add('Am șters autorul.', 'success');
  Util::redirectToRoute('artist/list');
}

if ($saveButton) {
  $artist->name = Request::get('name');
  $artist->email = Request::get('email');
  $artist->label = Request::get('label');
  $artist->credits = Request::get('credits');
  $artist->sponsor = Request::has('sponsor');
  $artist->hidden = Request::has('hidden');

  if (validate($artist)) {
    $artist->save();
    Log::info("Added/saved author {$artist->id} ({$artist->name})");
    FlashMessage::add('Am salvat modificările.', 'success');
    Util::redirectToRoute('artist/list');
  }
}

Smart::assign('artist', $artist);
Smart::display('artist/edit.tpl');

/**
 * Returns true on success, false on errors.
 */
function validate($artist) {
  $success = true;
  if (!$artist->name) {
    FlashMessage::add('Numele nu poate fi vid.');
  }
  if (!$artist->label) {
    FlashMessage::add('Codul nu poate fi vid (îl folosim încă la cuvântul lunii).');
  }

  $other = Model::factory('WotdArtist')
         ->where('label', $artist->label)
         ->where_not_equal('id', (int) $artist->id) // could be "" when adding a new artist
         ->find_one();
  if ($other) {
    FlashMessage::add('Codul este deja folosit.');
  }

  return !FlashMessage::hasErrors();
}
