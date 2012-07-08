<?php
/**
 * Allows registered users to select and manage "favorite" Zenphoto objects.
 * Currently just images are supported.
 *
 * @author Stephen Billard (sbillard)
 * @package plugins
 */
$plugin_is_filter = 5|ADMIN_PLUGIN|THEME_PLUGIN;
$plugin_description = gettext('Support for <em>favorites</em> handling.');
$plugin_author = "Stephen Billard (sbillard)";

$option_interface = 'favoritesOptions';

zp_register_filter('theme_head', 'favorites::theme_head');

class favoritesOptions {

	function __construct() {
		setOptionDefault('favorites_linkpage', 'favorites');
		gettext($str = 'My favorites');
		setOptionDefault('favorites_linktext', getAllTranslations($str));
		gettext($str = 'Add to favorites');
		setOptionDefault('favorites_add_button', getAllTranslations($str));
		gettext($str = 'Remove from favorites');
		setOptionDefault('favorites_remove_button', getAllTranslations($str));
	}

	function getOptionsSupported() {
		global $_zp_gallery;
		$themename = $_zp_gallery->getCurrentTheme();
		$curdir = getcwd();
		$root = SERVERPATH.'/'.THEMEFOLDER.'/'.$themename.'/';
		chdir($root);
		$filelist = safe_glob('*.php');
		$list = array();
		foreach($filelist as $file) {
			$file = str_replace('.php', '', filesystemToInternal(basename($file)));
			$list[$file] = $file;
		}
		$list = array_diff($list, standardScripts());

		$options = array(	gettext('Link text') => array('key' => 'favorites_linktext', 'type' => OPTION_TYPE_TEXTBOX,
																					'multilingual'=>true,
																					'order'=>3,
																					'desc' => gettext('The text for the link to the favorites page.')),
											gettext('Favorites page') => array('key' => 'favorites_linkpage', 'type' => OPTION_TYPE_SELECTOR,
																					'order'=>4,
																					'selections' => $list,
																					'desc' => gettext('Theme page for the favorites link')),
											gettext('Add button') => array('key' => 'favorites_add_button', 'type' => OPTION_TYPE_TEXTBOX,
																					'multilingual'=>true,
																					'order'=>1,
																					'desc' => gettext('Default text for the <em>add to favorites</em> button.')),
											gettext('Remove button') => array('key' => 'favorites_remove_button', 'type' => OPTION_TYPE_TEXTBOX,
																					'multilingual'=>true,
																					'order'=>2,
																					'desc' => gettext('Default text for the <em>remove from favorites</em> button.'))
		);
		return $options;
	}

	function handleOption($option, $currentValue) {
	}

}


class favorites extends AlbumBase {

	function __construct($user) {

		$this->name = $user;
		$this->setTitle(gettext('My favorites'));
	}

	function addImage($img) {
		$folder = $img->album->name;
		$filename = $img->filename;
		$sql = 'INSERT INTO '.prefix('plugin_storage').' (`type`, `aux`,`data`) VALUES ("favorites",'.db_quote($this->name).','.db_quote(serialize(array('type'=>'images', 'id'=>$folder.'/'.$filename))).')';
		query($sql);
	}

	function removeImage($img) {
		$folder = $img->album->name;
		$filename = $img->filename;
		$sql = 'DELETE FROM '.prefix('plugin_storage').' WHERE `type`="favorites" AND `aux`='.db_quote($this->name).' AND `data`='.db_quote(serialize(array('type'=>'images', 'id'=>$folder.'/'.$filename)));
		query($sql);
	}

	/**
	 * Returns all folder names for all the subdirectories.
	 *
	 * @param string $page  Which page of subalbums to display.
	 * @param string $sorttype The sort strategy
	 * @param string $sortdirection The direction of the sort
	 * @param bool $care set to false if the order does not matter
	 * @param bool $mine set true/false to override ownership
	 * @return array
	 */

	function getAlbums($page=0, $sorttype=null, $sortdirection=null, $care=true, $mine=NULL) {
		global $_zp_gallery;
		if (is_null($this->subalbums) || $care && $sorttype.$sortdirection !== $this->lastsubalbumsort ) {
			$subalbums = array();
			$result = query($sql = 'SELECT * FROM '.prefix('plugin_storage').' WHERE `type`="favorites" AND `aux`='.db_quote($this->name));
			if ($result) {
				while ($row = db_fetch_assoc($result)) {
					$row = unserialize($row['data']);
					if ($row['type']=='albums') {
						$subalbums[] = $row['id'];
					}
				}
				if (is_null($sorttype)) {
					$sorttype = $this->getAlbumSortType();
				}
				if (is_null($sortdirection)) {
					if ($this->getSortDirection('album')) {
						$sortdirection = 'DESC';
					} else {
						$sortdirection = '';
					}
				}
				$sortkey = str_replace('`','',$this->getAlbumSortKey($sorttype));
				if (($sortkey == 'sort_order') || ($sortkey == 'RAND()')) {
					// manual sort is always ascending
					$order = false;
				} else {
					if (!is_null($sortdirection)) {
						$order = strtoupper($sortdirection) == 'DESC';
					} else {
						$order = $this->getSortDirection('image');
					}
				}
				$this->subalbums = sortByKey($subalbums,$sortkey,$order);
				$this->lastsubalbumsort = $sorttype.$sortdirection;
			}
		}
		if ($page == 0) {
			return $this->subalbums;
		} else {
			$albums_per_page = max(1, getOption('albums_per_page'));
			return array_slice($this->subalbums, $albums_per_page*($page-1), $albums_per_page);
		}
	}


	/**
	 * Returns a of a slice of the images for this album. They will
	 * also be sorted according to the sort type of this album, or by filename if none
	 * has been set.
	 *
	 * @param string $page  Which page of images should be returned. If zero, all images are returned.
	 * @param int $firstPageCount count of images that go on the album/image transition page
	 * @param string $sorttype optional sort type
	 * @param string $sortdirection optional sort direction
	 * @param bool $care set to false if the order of the images does not matter
	 * @param bool $mine set true/false to override ownership
	 *
	 * @return array
	 */
	function getImages($page=0, $firstPageCount=0, $sorttype=null, $sortdirection=null, $care=true, $mine=NULL) {
		if (true || is_null($this->images) || $care && $sorttype.$sortdirection !== $this->lastimagesort) {
			$images = array();
			$result = query($sql = 'SELECT * FROM '.prefix('plugin_storage').' WHERE `type`="favorites" AND `aux`='.db_quote($this->name));
			if ($result) {
				while ($row = db_fetch_assoc($result)) {
					$row = unserialize($row['data']);
					if ($row['type']=='images') {
						$images[] = array('folder'=>dirname($row['id']),'filename'=>basename($row['id']));
					}
				}
				if (is_null($sorttype)) {
					$sorttype = $this->getSortType();
				}
				$sortkey = str_replace('`','',$this->getImageSortKey($sorttype));
				if (($sortkey == 'sort_order') || ($sortkey == 'RAND()')) {
					// manual sort is always ascending
					$order = false;
				} else {
					if (!is_null($sortdirection)) {
						$order = strtoupper($sortdirection) == 'DESC';
					} else {
						$order = $this->getSortDirection('image');
					}
				}
				$this->images = sortByKey($images,$sortkey,$order);
				$this->lastimagesort = $sorttype.$sortdirection;
			}
			// Return the cut of images based on $page. Page 0 means show all.
			if ($page == 0) {
				return $this->images;
			} else {
				// Only return $firstPageCount images if we are on the first page and $firstPageCount > 0
				if (($page==1) && ($firstPageCount>0)) {
					$pageStart = 0;
					$images_per_page = $firstPageCount;

				} else {
					if ($firstPageCount>0) {
						$fetchPage = $page - 2;
					} else {
						$fetchPage = $page - 1;
					}
					$images_per_page = max(1, getOption('images_per_page'));
					$pageStart = $firstPageCount + $images_per_page * $fetchPage;

				}
				return array_slice($this->images, $pageStart , $images_per_page);
			}

		}
		return $images;
	}

	static function theme_head() {
		global $_zp_current_album, $_zp_gallery_page, $_myFavorites;
		if ($_zp_gallery_page == 'favorites.php') {
			$_zp_current_album = $_myFavorites;
			add_context(ZP_ALBUM);
		}
	}

}

if (!OFFSET_PATH && zp_loggedin()) {
	$_myFavorites = new favorites($_zp_current_admin_obj->getUser());

	if (isset($_POST['addToFavorites'])) {
		$id = sanitize($_POST['id']);
		switch ($_POST['type']) {
			case 'images':
				$img = newImage(NULL, array('folder'=>dirname($id), 'filename'=>basename($id)));
				if ($_POST['addToFavorites']) {
					if ($img->loaded) {
						$_myFavorites->addImage($img);
					}
				} else {
					$_myFavorites->removeImage($img);
				}
				break;
			case 'albums':
				$alb = new Album(NULL, $id);
				if ($_POST['addToFavorites']) {
					if ($img->loaded) {
						$_myFavorites->addAlbum($alb);
					}
				} else {
					$_myFavorites->removeAlbum($alb);
				}
				break;
		}
	}

	function printAddToFavorites($obj, $add=NULL, $remove=NULL) {
		global $_myFavorites;
		$v = 1;
		if (is_null($add)) {
			$add = getOption('favorites_add_button');
		}
		if (is_null($remove)) {
			$remove = getOption('favorites_remove_button');
		} else {
			$add = $remove;
		}
		$table = $obj->table;
		$target = array('type'=>$table);
		switch ($table) {
			case 'images':
				$id = $obj->album->name.'/'.$obj->filename;
				$images = $_myFavorites->getImages(0);
				foreach ($images as $image) {
					if ($image['folder']==$obj->album->name && $image['filename']==$obj->filename) {
						$v = 0;
						$add = $remove;
						break;
					}
				}
				break;
			case 'albums':
				$id = $obj->album->name;
				$albums = $_myFavorites->getAlbums(0);
				foreach ($albums as $album) {
					if ($album==$id) {
						$v = 0;
						$add = $remove;
						break;
					}
				}
				break;
		}

		?>
		<form name="imageFavorites" class="imageFavorites" id="imageFavorites<?php echo $obj->getID(); ?>" vaction="<?php echo sanitize($_SERVER['REQUEST_URI']); ?>" method="post" accept-charset="UTF-8">
			<input type="hidden" name="addToFavorites" value="<?php echo $v; ?>" />
			<input type="hidden" name="type" value="<?php echo html_encode($table); ?>" />
			<input type="hidden" name="id" value="<?php echo html_encode($id); ?>" />
			<span id="submit_button">
				<input type="submit" value="<?php echo $add; ?>" />
			</span>
		</form>
		<br clear="all" />
		<?php
	}

	function printFavoritesLink($text=NULL) {
		if (is_null($text)) {
			$text = getOption('favorites_linktext');
		}
		?>
		<a href="<?php echo FULLWEBPATH; ?>/page/<?php echo getOption('favorites_linkpage')?>"><?php echo $text; ?></a>
		<?php
	}

}
?>