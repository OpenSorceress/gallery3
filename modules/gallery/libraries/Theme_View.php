<?php defined("SYSPATH") or die("No direct script access.");
/**
 * Gallery - a web based photo album viewer and editor
 * Copyright (C) 2000-2009 Bharat Mediratta
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or (at
 * your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street - Fifth Floor, Boston, MA  02110-1301, USA.
 */
class Theme_View_Core extends View {
  private $theme_name = null;
  private $scripts = array();

  /**
   * Attempts to load a view and pre-load view data.
   *
   * @throws  Kohana_Exception  if the requested view cannot be found
   * @param   string  $name view name
   * @param   string  $page_type page type: album, photo, tags, etc
   * @param   string  $theme_name view name
   * @return  void
   */
  public function __construct($name, $page_type) {
    $theme_name = module::get_var("gallery", "active_site_theme");
    if (!file_exists("themes/$theme_name")) {
      module::set_var("gallery", "active_site_theme", "default");
      theme::load_themes();
      Kohana::log("error", "Unable to locate theme '$theme_name', switching to default theme.");
    }
    parent::__construct($name);

    $this->theme_name = module::get_var("gallery", "active_site_theme");
    if (user::active()->admin) {
      $this->theme_name = Input::instance()->get("theme", $this->theme_name);
    }
    $this->item = null;
    $this->tag = null;
    $this->set_global("theme", $this);
    $this->set_global("user", user::active());
    $this->set_global("page_type", $page_type);
    $this->set_global("page_title", null);
    if ($page_type == "album") {
      $this->set_global("thumb_proportion", $this->thumb_proportion());
    }

    $maintenance_mode = Kohana::config("core.maintenance_mode", false, false);
    if ($maintenance_mode) {
      message::warning(t("This site is currently in maintenance mode"));
    }
  }

  /**
   * Proportion of the current thumb_size's to default
   * @return int
   */
  public function thumb_proportion() {
    // @TODO change the 200 to a theme supplied value when and if we come up with an
    // API to allow the theme to set defaults.
    return module::get_var("gallery", "thumb_size", 200) / 200;
  }

  public function url($path, $absolute_url=false, $no_root=false) {
    $arg = "themes/{$this->theme_name}/$path";
    return $absolute_url ? url::abs_file($arg) : $no_root ? $arg : url::file($arg);
  }

  public function item() {
    return $this->item;
  }

  public function tag() {
    return $this->tag;
  }

  public function page_type() {
    return $this->page_type;
  }

  public function display($page_name, $view_class="View") {
    return new $view_class($page_name);
  }

  public function site_menu() {
    $menu = Menu::factory("root");
    if ($this->page_type != "login") {
      gallery_menu::site($menu, $this);

      foreach (module::active() as $module) {
        if ($module->name == "gallery") {
          continue;
        }
        $class = "{$module->name}_menu";
        if (method_exists($class, "site")) {
          call_user_func_array(array($class, "site"), array(&$menu, $this));
        }
      }
    }

    $menu->compact();
    print $menu;
  }

  public function album_menu() {
    $this->_menu("album");
  }

  public function tag_menu() {
    $this->_menu("tag");
  }

  public function photo_menu() {
    $this->_menu("photo");
  }

  private function _menu($type) {
    $menu = Menu::factory("root");
    call_user_func_array(array("gallery_menu", $type), array(&$menu, $this));
    foreach (module::active() as $module) {
      if ($module->name == "gallery") {
        continue;
      }
      $class = "{$module->name}_menu";
      if (method_exists($class, $type)) {
        call_user_func_array(array($class, $type), array(&$menu, $this));
      }
    }

    print $menu;
  }

  public function pager() {
    if ($this->children_count) {
      $this->pagination = new Pagination();
      $this->pagination->initialize(
        array("query_string" => "page",
              "total_items" => $this->children_count,
              "items_per_page" => $this->page_size,
              "style" => "classic"));
      return $this->pagination->render();
    }
  }

  /**
   * Print out any site wide status information.
   */
  public function site_status() {
    return site_status::get();
  }

  /**
   * Print out any messages waiting for this user.
   */
  public function messages() {
    return message::get();
  }

  public function script($file) {
    $this->scripts[$file] = 1;
  }

  private function _combine_script() {
    $links = array();
    $key = "";
    foreach (array_keys($this->scripts) as $file) {
      $path = DOCROOT . $file;
      if (file_exists($path)) {
        $stats = stat($path);
        $links[] = $path;
        // 7 == size, 9 == mtime, see http://php.net/stat
        $key = "{$key}$file $stats[7] $stats[9],";
      } else {
        Kohana::log("warn", "Javascript file missing: " . $file);
      }
    }

    $key = md5($key);
    $file = "tmp/CombinedJavascript_$key";
    if (!file_exists(VARPATH . $file)) {
      $contents = '';
      foreach ($links as $link) {
        $contents .= file_get_contents($link);
      }
      file_put_contents(VARPATH . $file, $contents);
      if (function_exists("gzencode")) {
        file_put_contents(VARPATH . "{$file}_gzip", gzencode($contents, 9, FORCE_GZIP));
      }
    }

    return "<script type=\"text/javascript\" src=\"" . url::site("javascript/combined/$key") .
      "\"></script>";
  }

  /**
   * Handle all theme functions that insert module content.
   */
  public function __call($function, $args) {
    switch ($function) {
    case "album_blocks":
    case "album_bottom":
    case "album_top":
    case "credits";
    case "dynamic_bottom":
    case "dynamic_top":
    case "footer":
    case "head":
    case "header_bottom":
    case "header_top":
    case "page_bottom":
    case "page_top":
    case "photo_blocks":
    case "photo_bottom":
    case "photo_top":
    case "resize_bottom":
    case "resize_top":
    case "sidebar_blocks":
    case "sidebar_bottom":
    case "sidebar_top":
    case "thumb_bottom":
    case "thumb_info":
    case "thumb_top":
      $blocks = array();
      if (method_exists("gallery_theme", $function)) {
        switch (count($args)) {
        case 0:
          $blocks[] = gallery_theme::$function($this);
          break;
        case 1:
          $blocks[] = gallery_theme::$function($this, $args[0]);
          break;
        case 2:
          $blocks[] = gallery_theme::$function($this, $args[0], $args[1]);
          break;
        default:
          $blocks[] = call_user_func_array(
            array("gallery_theme", $function),
            array_merge(array($this), $args));
        }

      }
      foreach (module::active() as $module) {
        if ($module->name == "gallery") {
          continue;
        }
        $helper_class = "{$module->name}_theme";
        if (method_exists($helper_class, $function)) {
          $blocks[] = call_user_func_array(
            array($helper_class, $function),
            array_merge(array($this), $args));
        }
      }

      if ($function == "head" || $function == "admin_head") {
        array_unshift($blocks, $this->_combine_script());
      }

      if (Session::instance()->get("debug")) {
        if ($function != "head") {
          array_unshift(
            $blocks, "<div class=\"gAnnotatedThemeBlock gAnnotatedThemeBlock_$function gClearFix\">" .
            "<div class=\"title\">$function</div>");
          $blocks[] = "</div>";
        }
      }
      return implode("\n", $blocks);

    default:
      throw new Exception("@todo UNKNOWN_THEME_FUNCTION: $function");
    }
  }
}