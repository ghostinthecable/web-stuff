<?php
/**
 * Banner: This script was developed by Daniel W.
 * Feel free to modify the directory paths and customise it for your project.
 * 
 * Description: 
 * This script scans a directory for HTML and PHP files, extracts unique HTML elements 
 * and their classes, and correlates the extracted classes with inline styles and 
 * external CSS files. It then outputs the unique classes and their associated styles, 
 * either from inline declarations or linked stylesheets.
 */

// Set the directory to scan for PHP and HTML files (modify this path as needed)
$directory = '/var/www/your_project/html/';

// Function: Recursively scan the directory for all PHP and HTML files
// Input: Directory path
// Output: Array of file paths for PHP and HTML files
function scan_directory_for_html_files($directory) {
    $files = [];
    $directoryIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    foreach ($directoryIterator as $file) {
        // Check for PHP and HTML files only
        if (pathinfo($file, PATHINFO_EXTENSION) === 'php' || pathinfo($file, PATHINFO_EXTENSION) === 'html') {
            $files[] = $file->getPathname(); // Get the full file path
        }
    }
    return $files;
}

// Function: Extract HTML elements and their classes from file content
// Input: HTML/PHP file content
// Output: Array of extracted elements with their tags, classes, and inline styles
function extract_elements_and_classes($content) {
    // Skip empty files
    if (empty(trim($content))) {
        return [];
    }

    // Use DOMDocument to parse the HTML content
    $dom = new DOMDocument();
    @$dom->loadHTML($content); // Suppress warnings for malformed HTML

    // Use XPath to search for all elements with a class attribute
    $xpath = new DOMXPath($dom);
    $elements = $xpath->query('//*[@class]');

    $result = [];
    foreach ($elements as $element) {
        $tag = $element->nodeName;
        $class = $element->getAttribute('class'); // Get the class attribute
        $inline_style = $element->getAttribute('style'); // Get inline styles, if any
        $result[] = [
            'tag' => $tag,
            'class' => $class,
            'inline_style' => $inline_style,
            'file' => '' // This will be populated with the filename later
        ];
    }
    return $result;
}

// Function: Extract linked CSS files from an HTML/PHP file
// Input: HTML/PHP file content
// Output: Array of file paths to the linked CSS files
function extract_linked_css($content) {
    // Skip empty files
    if (empty(trim($content))) {
        return [];
    }

    // Parse the HTML to find <link> tags with rel="stylesheet"
    $dom = new DOMDocument();
    @$dom->loadHTML($content);
    $xpath = new DOMXPath($dom);
    $css_links = $xpath->query('//link[@rel="stylesheet"]');

    $css_files = [];
    foreach ($css_links as $link) {
        $href = $link->getAttribute('href'); // Get the href attribute for the CSS file
        // Ensure we get absolute paths (assuming CSS files are in /var/www/your_project/css/)
        $css_files[] = realpath("/var/www/your_project/" . ltrim($href, '/'));
    }
    return array_filter($css_files); // Return valid paths only
}

// Function: Parse a CSS file and extract class names with their styles
// Input: CSS file path
// Output: Associative array of class names and their corresponding styles
function parse_css_classes_with_styles($css_file_path) {
    if (!file_exists($css_file_path)) return [];

    // Read the CSS file and match class declarations with styles
    $css_content = file_get_contents($css_file_path);
    preg_match_all('/\.([a-zA-Z0-9_-]+)\s*\{([^}]*)\}/', $css_content, $matches);

    $classes_with_styles = [];
    foreach ($matches[1] as $index => $class_name) {
        $styles = trim($matches[2][$index]);
        $classes_with_styles[$class_name] = $styles; // Map class names to their styles
    }

    return $classes_with_styles;
}

// Function: Process files, extract unique elements and classes, and map to CSS styles
// Input: Directory path
// Output: Arrays of unique elements and their associated CSS styles
function process_files_and_extract_styles($directory) {
    $html_files = scan_directory_for_html_files($directory);
    $unique_elements = [];
    $css_classes_map = [];

    // Process each HTML/PHP file
    foreach ($html_files as $file) {
        $content = file_get_contents($file);
        if ($content === false) {
            echo "Warning: Could not read file $file\n"; // Log if file cannot be read
            continue;
        }

        // Extract elements and classes from the file content
        $elements_and_classes = extract_elements_and_classes($content);
        // Extract linked CSS files from the file content
        $linked_css_files = extract_linked_css($content);

        // Parse the CSS files for class names and styles
        foreach ($linked_css_files as $css_file) {
            if (!isset($css_classes_map[$css_file])) {
                $css_classes_with_styles = parse_css_classes_with_styles($css_file);
                $css_classes_map[$css_file] = $css_classes_with_styles;
            }
        }

        // Add file information to each element for traceability
        foreach ($elements_and_classes as $element_data) {
            $element_data['file'] = $file;
            $unique_key = $element_data['tag'] . '::' . $element_data['class'];
            if (!array_key_exists($unique_key, $unique_elements)) {
                $unique_elements[$unique_key] = $element_data;
            }
        }
    }

    return [$unique_elements, $css_classes_map];
}

// Function: Output the unique classes and their associated styles (inline or external)
// Input: Unique elements array and CSS classes map
// Output: Prints the unique classes and their current styles
function output_styles_to_change($unique_elements, $css_classes_map) {
    $unique_classes = [];

    // Collect unique classes from the extracted elements
    foreach ($unique_elements as $element_data) {
        $class_names = explode(' ', $element_data['class']); // Multiple classes separated by spaces
        foreach ($class_names as $class_name) {
            $unique_classes[$class_name] = isset($unique_classes[$class_name]) ? $unique_classes[$class_name] : [];
            $unique_classes[$class_name]['inline_styles'][] = $element_data['inline_style']; // Collect inline styles if any
            $unique_classes[$class_name]['files'][] = $element_data['file']; // Collect the file names where the class is found
        }
    }

    // Output each class and its associated styles
    echo "Unique Classes and Their Current Styles:\n\n";
    foreach ($unique_classes as $class_name => $class_data) {
        echo "Class: .$class_name\n";

        // Check for inline styles
        foreach ($class_data['inline_styles'] as $inline_style) {
            if (!empty($inline_style)) {
                echo "  Inline Style: $inline_style\n";
            }
        }

        // Check for matching styles in the CSS files
        foreach ($css_classes_map as $css_file => $classes_with_styles) {
            if (isset($classes_with_styles[$class_name])) {
                echo "  Style in $css_file: {$classes_with_styles[$class_name]}\n";
            }
        }

        // Print the file locations
        $file_list = implode(", ", array_unique($class_data['files']));
        echo "  Found in files: $file_list\n";
        echo "-------------------------\n";
    }
}

// Run the process
list($unique_elements, $css_classes_map) = process_files_and_extract_styles($directory);
output_styles_to_change($unique_elements, $css_classes_map);

?>
