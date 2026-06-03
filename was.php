<?php
// Clear output buffer and set headers
ob_start();
header('Content-Type: image/png');

// Updated tree structure
$tree = [
    ['name' => 'Root 1', 'children' => [
        ['name' => 'Child 1', 'children' => [
            ['name' => 'Sub-child 1', 'children' => []]
        ]],
        ['name' => 'Child 2', 'children' => []]
    ]],
    ['name' => 'Root 2', 'children' => [
        ['name' => 'Child 3', 'children' => [
            ['name' => 'Sub-child 2', 'children' => [
                ['name' => 'Sub-child 55', 'children' => []]
            ]],
            ['name' => 'Sub-child 3', 'children' => []]
        ]],
        ['name' => 'Child 4', 'children' => []],
        ['name' => 'Was', 'children' => []]
    ]]
];

// Function to draw the tree
function drawTree($image, $tree, $x, $y, $level = 1, $spacing = 150, $horizontalSpacing = 400): void
{
    $box_width = 80;
    $box_height = 30;

    // Calculate the initial X position for top-level nodes
    $initial_x = $x - (count($tree) - 1) * $horizontalSpacing / 2;

    foreach ($tree as $node) {
        // Draw the current node
        imagefilledrectangle($image, $initial_x, $y, $initial_x + $box_width, $y + $box_height, imagecolorallocate($image, 200, 200, 200));
        imagerectangle($image, $initial_x, $y, $initial_x + $box_width, $y + $box_height, imagecolorallocate($image, 0, 0, 0));
        imagestring($image, 3, $initial_x + 10, $y + 10, $node['name'], imagecolorallocate($image, 0, 0, 0));

        // Draw children nodes
        if (!empty($node['children'])) {
            // Calculate horizontal start and end points for children
            $child_x = $initial_x - ($spacing / 2 * (count($node['children']) - 1));
            $child_y = $y + 80;

            // Draw a vertical line from the parent to the children level
            $line_start_x = $initial_x + $box_width / 2;
            $line_start_y = $y + $box_height;
            $line_end_y = $child_y - 20; // Leave a gap before connecting horizontally
            imageline($image, $line_start_x, $line_start_y, $line_start_x, $line_end_y, imagecolorallocate($image, 0, 0, 0));

            // Draw horizontal line to span all children
            $line_start_x_h = $child_x + $box_width / 2;
            $line_end_x_h = $child_x + ($spacing * (count($node['children']) - 1)) + $box_width / 2;
            imageline($image, $line_start_x_h, $line_end_y, $line_end_x_h, $line_end_y, imagecolorallocate($image, 0, 0, 0));

            // Draw each child node and connect them with vertical lines
            foreach ($node['children'] as $child) {
                // Draw a vertical line connecting the horizontal line to the child node
                $child_center_x = $child_x + $box_width / 2;
                imageline($image, $child_center_x, $line_end_y, $child_center_x, $child_y, imagecolorallocate($image, 0, 0, 0));

                // Recursively draw the child tree
                drawTree($image, [$child], $child_x, $child_y, $level + 1, $spacing / 1.5);
                $child_x += $spacing;
            }
        }

        // Shift horizontally for the next top-level node
        $initial_x += $horizontalSpacing;
    }
}


// Create image
$width = 1200;
$height = 800;
$image = imagecreatetruecolor($width, $height);
imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));

// Draw the tree
drawTree($image, $tree, $width / 2, 50);

// Output the image
imagepng($image, "test.png");
imagedestroy($image);
