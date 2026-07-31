<?php
/*
 * Preview reimplementation of the pfSense Form_* classes.
 *
 * Same public API the real classes expose (the subset the WireGuard pages and
 * wgeasy use), rendering Bootstrap 3 markup close to what pfSense produces.
 * This is a preview aid: on the firewall the real classes are used.
 */

function form_h($value) {
	return htmlspecialchars((string) $value, ENT_QUOTES);
}

abstract class Form_Element {
	protected $_classes	= array();
	protected $_attributes	= array();

	public $column_width	= null;

	public function addClass($class) {
		foreach (func_get_args() as $class) {
			$this->_classes[] = $class;
		}

		return $this;
	}

	public function setAttribute($name, $value) {
		$this->_attributes[$name] = $value;

		return $this;
	}

	public function setWidth($width) {
		$this->column_width = $width;

		return $this;
	}

	public function setIsRequired() {
		return $this;
	}

	public function setReadonly() {
		$this->_attributes['readonly'] = 'readonly';

		return $this;
	}

	public function setDisabled() {
		$this->_attributes['disabled'] = 'disabled';

		return $this;
	}

	public function setPattern($pattern) {
		$this->_attributes['pattern'] = $pattern;

		return $this;
	}

	public function setPlaceholder($text) {
		$this->_attributes['placeholder'] = $text;

		return $this;
	}

	public function setOnclick($js) {
		$this->_attributes['onclick'] = $js;

		return $this;
	}

	public function addMask($name, $value, $max = 128, $min = 0) {
		return $this;
	}

	protected function renderClasses($extra = array()) {
		$classes = array_merge($extra, $this->_classes);

		return empty($classes) ? '' : ' class="' . form_h(implode(' ', array_unique($classes))) . '"';
	}

	protected function renderAttributes() {
		$out = '';

		foreach ($this->_attributes as $name => $value) {
			if (is_null($value) || ($value === false)) {
				continue;
			}

			$out .= ' ' . form_h($name) . '="' . form_h($value) . '"';
		}

		return $out;
	}
}

abstract class Form_Field extends Form_Element {
	public $name;
	public $title;
	public $help = null;

	public function setHelp($help) {
		$this->help = (func_num_args() > 1) ? call_user_func_array('sprintf', func_get_args()) : $help;

		return $this;
	}

	public function getTitle() {
		return ltrim((string) $this->title, '*');
	}

	public function isRequired() {
		return (substr((string) $this->title, 0, 1) == '*');
	}

	protected function renderHelp() {
		return empty($this->help) ? '' : '<span class="help-block">' . $this->help . '</span>';
	}

	abstract public function renderControl();

	public function render($width = null) {
		$width = is_null($this->column_width) ? $width : $this->column_width;

		$width = is_null($width) ? 10 : $width;

		return "<div class=\"col-sm-{$width}\">" . $this->renderControl() . $this->renderHelp() . '</div>';
	}
}

class Form_Input extends Form_Field {
	public $type;
	public $value;

	public function __construct($name, $title, $type = 'text', $value = null, $attributes = array()) {
		$this->name		= $name;
		$this->title		= $title;
		$this->type		= $type;
		$this->value		= $value;
		$this->_attributes	= (array) $attributes;
	}

	public function renderControl() {
		$classes = ($this->type == 'hidden') ? array() : array('form-control');

		return '<input type="' . form_h($this->type) . '" name="' . form_h($this->name) . '" id="' . form_h($this->name) . '"'
			. ' value="' . form_h($this->value) . '"' . $this->renderClasses($classes) . $this->renderAttributes() . ' />';
	}
}

class Form_IpAddress extends Form_Input {
	public function __construct($name, $title, $value = null, $type = 'BOTH', $attributes = array()) {
		parent::__construct($name, $title, 'text', $value, $attributes);
	}
}

class Form_Textarea extends Form_Field {
	public $value;

	public function __construct($name, $title, $value = null) {
		$this->name	= $name;
		$this->title	= $title;
		$this->value	= $value;
	}

	public function renderControl() {
		return '<textarea name="' . form_h($this->name) . '" id="' . form_h($this->name) . '"'
			. $this->renderClasses(array('form-control')) . $this->renderAttributes() . '>' . form_h($this->value) . '</textarea>';
	}
}

class Form_Select extends Form_Field {
	public $value;
	public $values;
	public $multiple;

	public function __construct($name, $title, $value = null, $values = array(), $multiple = false) {
		$this->name	= $name;
		$this->title	= $title;
		$this->value	= $value;
		$this->values	= (array) $values;
		$this->multiple	= $multiple;
	}

	public function renderControl() {
		$out = '<select name="' . form_h($this->name) . '" id="' . form_h($this->name) . '"'
			. $this->renderClasses(array('form-control')) . $this->renderAttributes()
			. ($this->multiple ? ' multiple="multiple"' : '') . '>';

		foreach ($this->values as $key => $label) {
			$selected = ((string) $key === (string) $this->value) ? ' selected="selected"' : '';

			$out .= '<option value="' . form_h($key) . '"' . $selected . '>' . form_h($label) . '</option>';
		}

		return $out . '</select>';
	}
}

class Form_Checkbox extends Form_Field {
	public $label;
	public $checked;
	public $value;

	public function __construct($name, $title, $label, $checked, $value = 'yes') {
		$this->name	= $name;
		$this->title	= $title;
		$this->label	= $label;
		$this->checked	= $checked;
		$this->value	= $value;
	}

	public function renderControl() {
		return '<label class="chkboxlbl"><input type="checkbox" name="' . form_h($this->name) . '" id="' . form_h($this->name) . '"'
			. ' value="' . form_h($this->value) . '"' . ($this->checked ? ' checked="checked"' : '')
			. $this->renderClasses() . $this->renderAttributes() . ' /> ' . $this->label . '</label>';
	}
}

class Form_Button extends Form_Field {
	public $link;
	public $icon;

	public function __construct($name, $title, $link = null, $icon = null) {
		$this->name	= $name;
		$this->title	= $title;
		$this->link	= $link;
		$this->icon	= $icon;
	}

	public function renderControl() {
		$icon = empty($this->icon) ? '' : '<i class="' . form_h($this->icon) . ' icon-embed-btn"></i>';

		if (!empty($this->link)) {
			return '<a href="' . form_h($this->link) . '" id="' . form_h($this->name) . '"'
				. $this->renderClasses(array('btn')) . $this->renderAttributes() . '>' . $icon . form_h($this->title) . '</a>';
		}

		return '<button type="submit" name="' . form_h($this->name) . '" id="' . form_h($this->name) . '"'
			. ' value="' . form_h($this->title) . '"' . $this->renderClasses(array('btn')) . $this->renderAttributes() . '>'
			. $icon . form_h($this->title) . '</button>';
	}
}

class Form_StaticText extends Form_Field {
	public $text;

	public function __construct($title, $text) {
		$this->title	= $title;
		$this->name	= 'statictext';
		$this->text	= $text;
	}

	public function renderControl() {
		return '<div class="form-control-static">' . $this->text . '</div>';
	}
}

class Form_Group extends Form_Element {
	public $label;

	protected $_inputs = array();

	public function __construct($label) {
		$this->label = $label;
	}

	public function add($input) {
		$this->_inputs[] = $input;

		return $input;
	}

	public function render() {
		$label = ltrim((string) $this->label, '*');

		$required = (substr((string) $this->label, 0, 1) == '*') ? ' <span class="text-danger">*</span>' : '';

		$out = '<div' . $this->renderClasses(array('form-group')) . '>';

		$out .= '<label class="col-sm-2 control-label"><span>' . form_h($label) . $required . '</span></label>';

		$width = (count($this->_inputs) > 1) ? null : 10;

		foreach ($this->_inputs as $input) {
			$out .= $input->render(is_null($width) ? (int) floor(10 / count($this->_inputs)) : $width);
		}

		return $out . '</div>';
	}
}

class Form_Section extends Form_Element {
	public $title;

	protected $_groups = array();

	public function __construct($title) {
		$this->title = $title;
	}

	public function addInput($input) {
		$group = new Form_Group($input->title);

		$group->add($input);

		$this->_groups[] = $group;

		return $input;
	}

	public function add($group) {
		$this->_groups[] = $group;

		return $group;
	}

	public function render() {
		$out = '<div class="panel panel-default"><div class="panel-heading"><h2 class="panel-title">'
			. form_h($this->title) . '</h2></div><div class="panel-body">';

		foreach ($this->_groups as $group) {
			$out .= $group->render();
		}

		return $out . '</div></div>';
	}
}

class Form extends Form_Element {
	protected $_sections	= array();
	protected $_globals	= array();

	public function __construct($save = true) {
		if ($save !== false) {
			$this->_globals[] = (new Form_Button('save', ($save === true) ? gettext('Save') : $save, null, 'fa-solid fa-save'))
						->addClass('btn-primary');
		}
	}

	public function add($section) {
		$this->_sections[] = $section;

		return $section;
	}

	public function addGlobal($input) {
		$this->_globals[] = $input;

		return $input;
	}

	/*
	 * Mirrors the real Form::__toString(): plain globals are appended inline,
	 * Form_Button globals are collected and rendered last inside an offset
	 * column, which is how pfSense draws the Save button.
	 */
	public function __toString() {
		$out = '<form action="" method="post" class="form-horizontal" name="iform" id="iform">';

		foreach ($this->_sections as $section) {
			$out .= $section->render();
		}

		$buttons = '';

		foreach ($this->_globals as $global) {
			if ($global instanceof Form_Button) {
				$buttons .= $global->renderControl();
			} else {
				$out .= $global->renderControl();
			}
		}

		if (!empty($buttons)) {
			$out .= '<div class="form-group"><div class="col-sm-10 col-sm-offset-2">' . $buttons . '</div></div>';
		}

		return $out . '</form>';
	}
}

?>
