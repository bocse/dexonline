<?php
require_once("../../phplib/util.php"); 
util_assertModerator(PRIV_EDIT | PRIV_STRUCT);
util_assertNotMirror();

handleLexemActions();

// We get some data as JSON because it is 2-dimensional (a list of lists)
// and PHP cannot parse the form data correctly.

// Lexem parameters
$lexemId = util_getRequestParameter('lexemId');
$lexemForm = util_getRequestParameter('lexemForm');
$lexemNumber = util_getRequestParameter('lexemNumber');
$lexemDescription = util_getRequestParameter('lexemDescription');
$lexemComment = util_getRequestParameter('lexemComment');
$needsAccent = util_getBoolean('needsAccent');
$stopWord = util_getBoolean('stopWord');
$hyphenations = util_getRequestParameter('hyphenations');
$pronunciations = util_getRequestParameter('pronunciations');
$entryId = util_getRequestParameter('entryId');
$variantIds = util_getRequestParameterWithDefault('variantIds', []);
$variantOfId = util_getRequestParameter('variantOfId');
$tagIds = util_getRequestParameter('tagIds');
$structStatus = util_getRequestIntParameter('structStatus');
$structuristId = util_getRequestIntParameter('structuristId');
$jsonMeanings = util_getRequestParameter('jsonMeanings');

// Paradigm parameters
$modelType = util_getRequestParameter('modelType');
$modelNumber = util_getRequestParameter('modelNumber');
$restriction = util_getRequestParameter('restriction');
$sourceIds = util_getRequestParameterWithDefault('sourceIds', []);
$notes = util_getRequestParameter('notes');
$isLoc = util_getBoolean('isLoc');

// Button parameters
$refreshLexem = util_getRequestParameter('refreshLexem');
$saveLexem = util_getRequestParameter('saveLexem');

$lexem = Lexem::get_by_id($lexemId);
$original = Lexem::get_by_id($lexemId); // Keep a copy so we can test whether certain fields have changed

if ($refreshLexem || $saveLexem) {
  populate($lexem, $original, $lexemForm, $lexemNumber, $lexemDescription, $lexemComment,
           $needsAccent, $stopWord, $hyphenations, $pronunciations, $entryId, $variantOfId,
           $structStatus, $structuristId, $modelType, $modelNumber, $restriction, $notes,
           $isLoc, $sourceIds);
  $meanings = json_decode($jsonMeanings);

  if (validate($lexem, $original, $variantIds, $meanings)) {
    // Case 1: Validation passed
    if ($saveLexem) {
      if (($original->modelType == 'VT') && ($lexem->modelType != 'VT')) {
        $original->deleteParticiple();
      }
      if (in_array($original->modelType, ['V', 'VT']) &&
          !in_array($lexem->modelType, ['V', 'VT'])) {
        $original->deleteLongInfinitive();
      }
      $lexem->deepSave();
      Meaning::saveTree($meanings, $lexem);
      $lexem->updateVariants($variantIds);
      $lexem->regenerateDependentLexems();

      // Delete the old tags and add the new tags.
      LexemTag::delete_all_by_lexemId($lexem->id);
      foreach ($tagIds as $tagId) {
        $lt = Model::factory('LexemTag')->create();
        $lt->lexemId = $lexem->id;
        $lt->tagId = $tagId;
        $lt->save();
      }

      Log::notice("Saved lexem {$lexem->id} ({$lexem->formNoAccent})");
      util_redirect("lexemEdit.php?lexemId={$lexem->id}");
    }
  } else {
    // Case 2: Validation failed
  }
  // Case 1-2: Page was submitted
  SmartyWrap::assign('variantIds', $variantIds);
  SmartyWrap::assign('meanings', Meaning::convertTree($meanings));
} else {
  // Case 3: First time loading this page
  $lexem->loadInflectedFormMap();

  $lts = LexemTag::get_all_by_lexemId($lexem->id);
  $tagIds = util_objectProperty($lts, 'tagId');

  SmartyWrap::assign('variantIds', $lexem->getVariantIds());
  // SmartyWrap::assign('meanings', Meaning::loadTree($lexem->id));
}

$tags = Model::factory('Tag')->order_by_asc('value')->find_many();

$ss = $lexem->structStatus;
$oss = $original->structStatus; // syntactic sugar

$canEdit = array(
  'general' => util_isModerator(PRIV_EDIT),
  'defStructured' => util_isModerator(PRIV_EDIT),
  'description' => util_isModerator(PRIV_EDIT),
  'form' => !$lexem->isLoc || util_isModerator(PRIV_LOC),
  'hyphenations' => ($ss == Lexem::STRUCT_STATUS_IN_PROGRESS) || util_isModerator(PRIV_EDIT),
  'loc' => (int)util_isModerator(PRIV_LOC),
  'paradigm' => util_isModerator(PRIV_EDIT),
  'pronunciations' => ($ss == Lexem::STRUCT_STATUS_IN_PROGRESS) || util_isModerator(PRIV_EDIT),
  'sources' => util_isModerator(PRIV_LOC | PRIV_EDIT),
  'structStatus' => ($oss == Lexem::STRUCT_STATUS_NEW) || ($oss == Lexem::STRUCT_STATUS_IN_PROGRESS) || util_isModerator(PRIV_EDIT),
  'structuristId' => util_isModerator(PRIV_ADMIN),
  'stopWord' => util_isModerator(PRIV_ADMIN),
  'tags' => util_isModerator(PRIV_LOC | PRIV_EDIT),
  'variants' => ($ss == Lexem::STRUCT_STATUS_IN_PROGRESS) || util_isModerator(PRIV_EDIT),
);

// Prepare a list of model numbers, to be used in the paradigm drop-down.
$models = FlexModel::loadByType($lexem->modelType);

SmartyWrap::assign('lexem', $lexem);
SmartyWrap::assign('homonyms', Model::factory('Lexem')->where('formNoAccent', $lexem->formNoAccent)->where_not_equal('id', $lexem->id)->find_many());
SmartyWrap::assign('tags', $tags);
SmartyWrap::assign('tagIds', $tagIds);
SmartyWrap::assign('modelTypes', Model::factory('ModelType')->order_by_asc('code')->find_many());
SmartyWrap::assign('models', $models);
SmartyWrap::assign('canEdit', $canEdit);
SmartyWrap::assign('structStatusNames', Lexem::$STRUCT_STATUS_NAMES);
SmartyWrap::assign('suggestHiddenSearchForm', true);
SmartyWrap::assign('suggestNoBanner', true);
SmartyWrap::addCss('jqueryui-smoothness', 'paradigm', 'bootstrap', 'select2');
SmartyWrap::addJs('jqueryui', 'select2', 'select2Dev', 'bootstrap', 'modelDropdown');
SmartyWrap::display('admin/lexemEdit.tpl');

/**************************************************************************/

// Populate lexem fields from request parameters.
function populate(&$lexem, &$original, $lexemForm, $lexemNumber, $lexemDescription, $lexemComment,
                  $needsAccent, $stopWord, $hyphenations, $pronunciations, $entryId, $variantOfId,
                  $structStatus, $structuristId, $modelType, $modelNumber, $restriction, $notes,
                  $isLoc, $sourceIds) {
  $lexem->setForm(AdminStringUtil::formatLexem($lexemForm));
  $lexem->number = $lexemNumber;
  $lexem->description = AdminStringUtil::internalize($lexemDescription, false);
  $lexem->comment = trim(AdminStringUtil::internalize($lexemComment, false));
  // Sign appended comments
  if (StringUtil::startsWith($lexem->comment, $original->comment) &&
      $lexem->comment != $original->comment &&
      !StringUtil::endsWith($lexem->comment, ']]')) {
    $lexem->comment .= " [[" . session_getUser() . ", " . strftime("%d %b %Y %H:%M") . "]]";
  }
  $lexem->noAccent = !$needsAccent;
  $lexem->stopWord = $stopWord;
  $lexem->hyphenations = $hyphenations;
  $lexem->pronunciations = $pronunciations;
  $lexem->entryId = $entryId;
  $lexem->variantOfId = $variantOfId ? $variantOfId : null;
  $lexem->structStatus = $structStatus;
  $lexem->structuristId = $structuristId;
  // Possibly overwrite the structuristId according to the structStatus change
  if (($original->structStatus == Lexem::STRUCT_STATUS_NEW) &&
      ($lexem->structStatus == Lexem::STRUCT_STATUS_IN_PROGRESS)) {
    $lexem->structuristId = session_getUserId();
  }

  $lexem->modelType = $modelType;
  $lexem->modelNumber = $modelNumber;
  $lexem->restriction = $restriction;
  $lexem->notes = $notes;
  $lexem->isLoc = $isLoc;
  $lexem->generateInflectedFormMap();

  // Create LexemSources
  $lexemSources = [];
  foreach ($sourceIds as $sourceId) {
    $ls = Model::factory('LexemSource')->create();
    $ls->sourceId = $sourceId;
    $lexemSources[] = $ls;
  }
  $lexem->setLexemSources($lexemSources);
}

function validate($lexem, $original, $variantIds, $meanings) {
  if (!$lexem->form) {
    FlashMessage::add('Forma nu poate fi vidă.');
  }

  $numAccents = mb_substr_count($lexem->form, "'");
  // Note: we allow multiple accents for lexems like hárcea-párcea
  if ($numAccents && $lexem->noAccent) {
    FlashMessage::add('Ați indicat că lexemul nu necesită accent, dar forma conține un accent.');
  } else if (!$numAccents && !$lexem->noAccent) {
    FlashMessage::add('Adăugați un accent sau debifați câmpul „Necesită accent”.');
  }

  $hasS = false;
  $hasP = false;
  for ($i = 0; $i < mb_strlen($lexem->restriction); $i++) {
    $c = StringUtil::getCharAt($lexem->restriction, $i);
    if ($c == 'T' || $c == 'U' || $c == 'I') {
      if ($lexem->modelType != 'V' && $lexem->modelType != 'VT') {
        FlashMessage::add("Restricția <b>$c</b> se aplică numai verbelor");
      }
    } else if ($c == 'S') {
      if ($lexem->modelType == 'I' || $lexem->modelType == 'T') {
        FlashMessage::add("Restricția <b>S</b> nu se aplică modelului $lexem->modelType");
      }
      $hasS = true;
    } else if ($c == 'P') {
      if ($lexem->modelType == 'I' || $lexem->modelType == 'T') {
        FlashMessage::add("Restricția <b>P</b> nu se aplică modelului $lexem->modelType");
      }
      $hasP = true;
    }
  }
  
  if ($hasS && $hasP) {
    FlashMessage::add("Restricțiile <b>S</b> și <b>P</b> nu pot coexista.");
  }

  $ifs = $lexem->generateInflectedForms();
  if (!is_array($ifs)) {
    $infl = Inflection::get_by_id($ifs);
    FlashMessage::add(sprintf("Nu pot genera flexiunea '%s' conform modelului %s%s",
                              htmlentities($infl->description), $lexem->modelType, $lexem->modelNumber));
  }

  $variantOf = Lexem::get_by_id($lexem->variantOfId);
  if ($variantOf && !goodForVariantJson($meanings)) {
    FlashMessage::add("Acest lexem este o variantă a lui {$variantOf} și nu poate avea el însuși sensuri. " .
                      "Este permis doar un sens, fără conținut, pentru indicarea surselor și a registrelor de folosire.");
  }
  if ($variantOf && !empty($variantIds)) {
    FlashMessage::add("Acest lexem este o variantă a lui {$variantOf} și nu poate avea el însuși variante.");
  }
  if ($variantOf && ($variantOf->id == $lexem->id)) {
    FlashMessage::add("Lexemul nu poate fi variantă a lui însuși.");
  }

  foreach ($variantIds as $variantId) {
    $variant = Lexem::get_by_id($variantId);
    if ($variant->id == $lexem->id) {
      FlashMessage::add('Lexemul nu poate fi variantă a lui însuși.');
    }
    if ($variant->variantOfId && $variant->variantOfId != $lexem->id) {
      $other = Lexem::get_by_id($variant->variantOfId);
      FlashMessage::add("\"{$variant}\" este deja marcat ca variantă a lui \"{$other}\".");
    }
    $variantVariantCount = Model::factory('Lexem')->where('variantOfId', $variant->id)->count();
    if ($variantVariantCount) {
      FlashMessage::add("\"{$variant}\" are deja propriile lui variante.");
    }
    $variantMeanings = Model::factory('Meaning')->where('lexemId', $variant->id)->find_many();
    if (!goodForVariant($variantMeanings)) {
      FlashMessage::add("'{$variant}' are deja propriile lui sensuri.");
    }
  }

  if (($lexem->structStatus == Lexem::STRUCT_STATUS_DONE) &&
      ($original->structStatus != Lexem::STRUCT_STATUS_DONE) &&
      !util_isModerator(PRIV_EDIT)) {
    FlashMessage::add("Doar moderatorii pot marca structurarea drept terminată. Vă rugăm să folosiți valoarea „așteaptă moderarea”.");
  }

  if ($lexem->structuristId != $original->structuristId) {
    if (util_isModerator(PRIV_ADMIN)) {
      // Admins can modify this field
    } else if (($original->structuristId == session_getUserId()) &&
               !$lexem->structuristId) {
      // Structurists can remove themselves
    } else if (!$original->structuristId &&
               ($lexem->structuristId == session_getUserId()) &&
               ($original->structStatus == Lexem::STRUCT_STATUS_NEW) &&
               ($lexem->structStatus == Lexem::STRUCT_STATUS_IN_PROGRESS)) {
      // The system silently assigns structurists when they start the process
    } else if (!$original->structuristId &&
               ($lexem->structuristId == session_getUserId()) &&
               ($original->structStatus == Lexem::STRUCT_STATUS_IN_PROGRESS) &&
               ($lexem->structStatus == Lexem::STRUCT_STATUS_IN_PROGRESS)) {
      // Structurists can claim orphan lexems
    } else {
      FlashMessage::add('Nu puteți modifica structuristul, dar puteți (1) revendica un lexem în lucru fără structurist sau ' .
                        '(2) renunța la un lexem dacă vi se pare prea greu de structurat.');
    }
  }

  return !FlashMessage::hasErrors();
}

/* Variants can only have one empty meaning, used to list the variant's sources. */
function goodForVariant($meanings) {
  if (empty($meanings)) {
    return true;
  }
  if (count($meanings) > 1) {
    return false;
  }
  $m = $meanings[0];
  $mss = MeaningSource::get_all_by_meaningId($m->id);
  $relations = Relation::get_all_by_meaningId($m->id);
  return count($mss) &&
    !$m->internalRep &&
    !$m->internalEtymology &&
    !$m->internalComment &&
    empty($relations);
}

/* Same, but for a JSON object. */
function goodForVariantJson($meanings) {
  if (empty($meanings)) {
    return true;
  }
  if (count($meanings) > 1) {
    return false;
  }

  $m = $meanings[0];
  if (empty($m->sourceIds) || $m->internalRep || $m->internalEtymology || $m->internalComment) {
    return false;
  }

  for ($i = 1; $i <= Relation::NUM_TYPES; $i++) {
    if (!empty($m->relationIds[$i])) {
      return false;
    }
  }

  return true;
}

/* This page handles a lot of actions. Move the minor ones here so they don't clutter the preview/save actions,
   which are hairy enough by themselves. */
function handleLexemActions() {
  $lexemId = util_getRequestParameter('lexemId');
  $lexem = Lexem::get_by_id($lexemId);

  $deleteLexem = util_getRequestParameter('deleteLexem');
  if ($deleteLexem) {
    $homonyms = Model::factory('Lexem')->where('formNoAccent', $lexem->formNoAccent)->where_not_equal('id', $lexem->id)->find_many();
    $lexem->delete();
    SmartyWrap::assign('lexem', $lexem);
    SmartyWrap::assign('homonyms', $homonyms);
    SmartyWrap::displayAdminPage('admin/lexemDeleted.tpl');
    exit;
  }

  $cloneLexem = util_getRequestParameter('cloneLexem');
  if ($cloneLexem) {
    $newLexem = $lexem->cloneLexem();
    Log::notice("Cloned lexem {$lexem->id} ({$lexem->formNoAccent}), new id is {$newLexem->id}");
    util_redirect("lexemEdit.php?lexemId={$newLexem->id}");
  }
}

?>
