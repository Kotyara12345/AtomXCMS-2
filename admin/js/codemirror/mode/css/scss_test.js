(() => {
  const mode = CodeMirror.getMode({ tabSize: 4 }, "text/x-scss");
  
  const tests = [
    { name: 'url_with_quotation', expected: "[tag foo] { [property background][operator :][string-2 url]([string test.jpg]) }" },
    { name: 'url_with_double_quotes', expected: "[tag foo] { [property background][operator :][string-2 url]([string \"test.jpg\"]) }" },
    { name: 'url_with_single_quotes', expected: "[tag foo] { [property background][operator :][string-2 url]([string \'test.jpg\']) }" },
    { name: 'string', expected: "[def @import] [string \"compass/css3\"]" },
    { name: 'important_keyword', expected: "[tag foo] { [property background][operator :][string-2 url]([string \'test.jpg\']) [keyword !important] }" },
    { name: 'variable', expected: "[variable-2 $blue][operator :][atom #333]" },
    { name: 'variable_as_attribute', expected: "[tag foo] { [property color][operator :][variable-2 $blue] }" },
    { name: 'numbers', expected: "[tag foo] { [property padding][operator :][number 10px] [number 10] [number 10em] [number 8in] }" },
    { name: 'number_percentage', expected: "[tag foo] { [property width][operator :][number 80%] }" },
    { name: 'selector', expected: "[builtin #hello][qualifier .world]{}" },
    { name: 'singleline_comment', expected: "[comment // this is a comment]" },
    { name: 'multiline_comment', expected: "[comment /*foobar*/]" },
    { name: 'attribute_with_hyphen', expected: "[tag foo] { [property font-size][operator :][number 10px] }" },
    { name: 'string_after_attribute', expected: "[tag foo] { [property content][operator :][string \"::\"] }" },
    { name: 'directives', expected: "[def @include] [qualifier .mixin]" },
    { name: 'basic_structure', expected: "[tag p] { [property background][operator :][keyword red]; }" },
    { name: 'nested_structure', expected: "[tag p] { [tag a] { [property color][operator :][keyword red]; } }" },
    { name: 'mixin', expected: "[def @mixin] [tag table-base] {}" },
    { name: 'number_without_semicolon', expected: "[tag p] {[property width][operator :][number 12]}", additional: "[tag a] {[property color][operator :][keyword red];}" },
    { name: 'atom_in_nested_block', expected: "[tag p] { [tag a] { [property color][operator :][atom #000]; } }" },
    { name: 'interpolation_in_property', expected: "[tag foo] { [operator #{][variable-2 $hello][operator }:][atom #000]; }" },
    { name: 'interpolation_in_selector', expected: "[tag foo][operator #{][variable-2 $hello][operator }] { [property color][operator :][atom #000]; }" },
    { name: 'interpolation_error', expected: "[tag foo][operator #{][error foo][operator }] { [property color][operator :][atom #000]; }" },
    { name: 'divide_operator', expected: "[tag foo] { [property width][operator :][number 4] [operator /] [number 2] }" },
    { name: 'nested_structure_with_id_selector', expected: "[tag p] { [builtin #hello] { [property color][operator :][keyword red]; } }" }
  ];

  tests.forEach(({ name, expected, additional }) => {
    test.mode(name, mode, [expected, additional].filter(Boolean), "scss");
  });
})();
