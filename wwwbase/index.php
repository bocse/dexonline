<?php
require_once("../phplib/util.php");

smarty_assign('page_title', 'Dicționar explicativ al limbii române');
smarty_assign('hideZepuSmallLogo', '1');
smarty_assign('letters', range('a', 'z'));
smarty_displayPageWithSkin('index.ihtml');
?>
