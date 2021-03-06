<?php

/**
 * News4ward
 * a contentelement driven news/blog-system
 *
 * @author Christoph Wiechert <wio@psitrax.de>
 * @copyright 4ward.media GbR <http://www.4wardmedia.de>
 * @package news4ward
 * @filesource
 * @licence LGPL
 */

namespace Psi\News4ward\Module;

class Reader extends Module
{
    /**
   	 * Template
   	 * @var string
   	 */
   	protected $strTemplate = 'mod_news4ward_reader';


    /**
	 * Display a wildcard in the back end
	 * @return string
	 */
	public function generate()
	{
		if (TL_MODE == 'BE')
		{
			$objTemplate = new \BackendTemplate('be_wildcard');

			$objTemplate->wildcard = '### News4ward READER ###';
			$objTemplate->title = $this->headline;
			$objTemplate->id = $this->id;
			$objTemplate->link = $this->name;
			$objTemplate->href = 'contao/main.php?do=themes&amp;table=tl_module&amp;act=edit&amp;id=' . $this->id;

			return $objTemplate->parse();
		}

		$this->news_archives = $this->sortOutProtected(deserialize($this->news4ward_archives));

		// Return if there are no archives
		if (!is_array($this->news_archives) || count($this->news_archives) < 1)
		{
			return '';
		}

		// Read the alias from the url
		if(!preg_match("~.*".preg_quote($GLOBALS['objPage']->alias)."/([a-z0-9\._-]+).*~i",$this->Environment->request,$erg))
		{
			return '';
		}
		// strip suffix
		if(substr($erg[1],-strlen($GLOBALS['TL_CONFIG']['urlSuffix'])) == $GLOBALS['TL_CONFIG']['urlSuffix'])
		{
			$erg[1] = substr($erg[1],0,-strlen($GLOBALS['TL_CONFIG']['urlSuffix']));
		}

		$this->alias = $erg[1];


		// set the template
		if(strlen($this->news4ward_readerTemplate))
		{
			$this->strTemplate = $this->news4ward_readerTemplate;
		}

		return parent::generate();
	}


	/**
	 * Generate module
	 */
	protected function compile()
    {
		$this->import('\News4ward\Helper','Helper');

		// Set the item from the auto_item parameter
		if ($GLOBALS['TL_CONFIG']['useAutoItem'] && isset($_GET['auto_item']))
		{
			$this->Input->setGet('items', $this->Input->get('auto_item'));
		}

		/* build where */
		$where = array();
		$time = time();

		// news archives
		$where[] = 'tl_news4ward_article.pid IN('. implode(',', array_map('intval', $this->news_archives)) . ')';

		// published
		if(!BE_USER_LOGGED_IN)
		{
			$where[] = "(tl_news4ward_article.start='' OR tl_news4ward_article.start<".$time.") AND (tl_news4ward_article.stop='' OR tl_news4ward_article.stop>".$time.") AND tl_news4ward_article.status='published'";
		}

		// alias
		$where[] = 'tl_news4ward_article.alias = "'.mysql_real_escape_string($this->alias).'"';


		/* get the item */
		$objArticle = $this->Database->prepare("
			SELECT tl_news4ward_article.*, author AS authorId, user.name as author, user.email as authorEmail,
				(SELECT title FROM tl_news4ward WHERE tl_news4ward.id=tl_news4ward_article.pid) AS archive,
				(SELECT jumpTo FROM tl_news4ward WHERE tl_news4ward.id=tl_news4ward_article.pid) AS parentJumpTo
			FROM tl_news4ward_article
			LEFT JOIN tl_user AS user ON (tl_news4ward_article.author=user.id)
			WHERE ".implode(' AND ',$where))->execute();


		if(!$objArticle->numRows) return;

		$this->parseArticles($objArticle,$this->Template);

		// Add social Buttons
		$this->Template->socialButtons = deserialize($objArticle->social,true);

		// Add keywords and description
		if ($objArticle->keywords != '')
		{
			$GLOBALS['TL_KEYWORDS'] .= (strlen($GLOBALS['TL_KEYWORDS']) ? ', ' : '') . $objArticle->keywords;
		}
		if ($objArticle->description != '')
		{
			$GLOBALS['objPage']->description .= (!empty($GLOBALS['objPage']->description) ? ' ': '') . $objArticle->description;
		}

		// Add Page Title
		$GLOBALS['objPage']->title = $objArticle->title;

		// Add facebook meta data
		// debug with https://developers.facebook.com/tools/debug
		if($this->news4ward_facebookMeta)
		{
			$strTagEnding = ($GLOBALS['objPage']->outputFormat == 'xhtml') ? ' />' : '>';

			if($objArticle->useFacebookImage && $objArticle->facebookImage && is_file(TL_ROOT.'/'.$objArticle->facebookImage))
			{
				$GLOBALS['TL_HEAD'][] = '<meta property="og:image" content="'.$this->Environment->base.$objArticle->facebookImage.'"'.$strTagEnding;
			}
			else if($objArticle->teaserImage &&  is_file(TL_ROOT.'/'.$objArticle->teaserImage))
			{
				$GLOBALS['TL_HEAD'][] = '<meta property="og:image" content="'.$this->Environment->base.$objArticle->teaserImage.'"'.$strTagEnding;
			}
			$GLOBALS['TL_HEAD'][] = '<meta property="og:title" content="'.$objArticle->title.'"'.$strTagEnding;
			$GLOBALS['TL_HEAD'][] = '<meta property="og:url" content="'.$this->Environment->base.$this->Environment->request.'"'.$strTagEnding;
			$GLOBALS['TL_HEAD'][] = '<meta property="og:description" content="'.str_replace('"','\'',(($objArticle->description) ? $objArticle->description : strip_tags($objArticle->teaser))).'"'.$strTagEnding;
		}

		// find NEXT  article
		$strWhere = '';
		if(!BE_USER_LOGGED_IN)
		{
			$strWhere = "AND (a.start='' OR a.start<".$time.") AND (a.stop='' OR a.stop>".$time.") AND a.status='published'";
		}

		$objNextArticle = $this->Database->prepare("
			SELECT a.id, a.alias, a.title, (SELECT jumpTo FROM tl_news4ward WHERE tl_news4ward.id=a.pid) AS parentJumpTo
			FROM tl_news4ward_article AS a
			WHERE a.pid = ? AND a.start > ?".$strWhere.' ORDER BY start ASC')->limit(1)->execute($objArticle->pid, $objArticle->start);

		if($objNextArticle->numRows)
		{
			$this->Template->nextArticle = array
			(
				'title' => $objNextArticle->title,
				'href'	=> $this->Helper->generateUrl($objNextArticle)
			);
		}
		else
		{
			$this->Template->nextArticle = false;
		}

		// find PREVIOUS article
		$objPrevArticle = $this->Database->prepare("
			SELECT a.id, a.alias, a.title, (SELECT jumpTo FROM tl_news4ward WHERE tl_news4ward.id=a.pid) AS parentJumpTo
			FROM tl_news4ward_article AS a
			WHERE a.pid = ? AND a.start < ?".$strWhere.' ORDER BY start DESC')->limit(1)->execute($objArticle->pid, $objArticle->start);

		if($objPrevArticle->numRows)
		{
			$this->Template->prevArticle = array
			(
				'title' => $objPrevArticle->title,
				'href'	=> $this->Helper->generateUrl($objPrevArticle)
			);
		}
		else
		{
			$this->Template->prevArticle = false;
		}

    }


}
