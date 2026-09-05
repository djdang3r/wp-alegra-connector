<?php if(!defined('ABSPATH'))exit;
$page_title=__('Documentación','alegra-connector');
$page_subtitle=__('Guia completa del plugin Alegra Connector','alegra-connector');
include __DIR__.'/header.php';

// Simple markdown to HTML converter
function ac_md_to_html(string $md): string {
    // Step 1: Extract and protect fenced code blocks
    $code_blocks = [];
    $md = preg_replace_callback('/```\n?(.*?)\n?```/s', function($m) use (&$code_blocks) {
        $key = '%%CODEBLOCK' . count($code_blocks) . '%%';
        $code_blocks[$key] = '<pre><code>' . esc_html(trim($m[1])) . '</code></pre>';
        return $key;
    }, $md);

    // Step 2: Escape HTML
    $md = esc_html($md);

    // Step 3: Restore code blocks
    $md = strtr($md, $code_blocks);

    // Step 4: Headers
    $md = preg_replace('/^### (.+)$/m','<h3>$1</h3>',$md);
    $md = preg_replace('/^## (.+)$/m','<h2>$1</h2>',$md);
    $md = preg_replace('/^# (.+)$/m','<h1>$1</h1>',$md);
    // Bold
    $md = preg_replace('/\*\*(.+?)\*\*/','<strong>$1</strong>',$md);
    // Inline code
    $md = preg_replace('/`([^`]+)`/','<code>$1</code>',$md);
    // Links (with javascript: / data: URL protection)
    $md = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/', function($m) {
        $url = trim($m[2]);
        // Block dangerous schemes
        if (preg_match('/^(javascript|data|vbscript|file):/i', $url)) {
            return esc_html($m[1]); // Render as plain text
        }
        $safe_url = esc_url($url);
        return '<a href="' . $safe_url . '" target="_blank" rel="noopener noreferrer">' . esc_html($m[1]) . '</a>';
    }, $md);
    // Images (with javascript: / data: URL protection)
    $md = preg_replace_callback('/!\[([^\]]*)\]\(([^)]+)\)/', function($m) {
        $url = trim($m[2]);
        if (preg_match('/^(javascript|data|vbscript|file):/i', $url)) {
            return ''; // Strip dangerous images entirely
        }
        $safe_url = esc_url($url);
        $alt = esc_attr($m[1]);
        return '<img src="' . $safe_url . '" alt="' . $alt . '" style="max-width:100%;">';
    }, $md);
    // Horizontal rules
    $md = preg_replace('/^---$/m','<hr>',$md);
    // Lists
    $md = preg_replace('/^- (.+)$/m','<li>$1</li>',$md);
    $md = preg_replace('/((?:<li>.*<\/li>\n?)+)/','<ul>$1</ul>',$md);
    // Ordered lists
    $md = preg_replace('/^\d+\. (.+)$/m','<li>$1</li>',$md);

    // Tables (simple)
    $md = preg_replace_callback('/\|(.+)\|\n\|[-| :]+\|\n((?:\|.+\|\n?)+)/',function($m){
        $rows = explode("\n",trim($m[2]));
        $html = '<table class="ac-doc-table"><thead><tr>';
        $heads = explode('|',trim($m[1],'|'));
        foreach($heads as $h) $html .= '<th>'.trim($h).'</th>';
        $html .= '</tr></thead><tbody>';
        foreach($rows as $row){
            $html .= '<tr>';
            $cells = explode('|',trim($row,'|'));
            foreach($cells as $cell) $html .= '<td>'.trim($cell).'</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        return $html;
    },$md);

    // Paragraphs
    $blocks = preg_split('/\n\n+/',$md);
    $md = '';
    foreach($blocks as $b){
        $b = trim($b);
        if(empty($b)) continue;
        if(preg_match('/^<(h[1-3]|ul|ol|table|pre|hr|img)/',$b)) $md .= $b;
        else $md .= '<p>'.$b.'</p>';
    }
    return $md;
}

// Split content by ## sections for navigation
$sections = preg_split('/\n(?=## )/', $content);
$toc = [];
foreach($sections as $i => $s){
    if(preg_match('/^## (.+)/',$s,$m)) $toc[] = ['id'=>'s'.$i,'title'=>trim($m[1])];
}
?>
<div class="alegra-connector-wrap">

<!-- TOC Navigation -->
<?php if(!empty($toc)):?>
<div class="ac-card" style="margin-bottom:16px;border-left:4px solid var(--ac-primary);">
    <div class="ac-card-header"><h2><?php esc_html_e('Indice','alegra-connector');?></h2></div>
    <div style="display:flex;flex-wrap:wrap;gap:6px;">
        <?php foreach($toc as $item):?>
        <a href="#<?php echo esc_attr($item['id']);?>" class="ac-btn ac-btn-xs"><?php echo esc_html($item['title']);?></a>
        <?php endforeach;?>
    </div>
</div>
<?php endif;?>

<!-- Documentation Content -->
<?php if(!empty($sections)):?>
    <?php foreach($sections as $i => $section): $sid = 's'.$i;?>
    <div class="ac-card" style="margin-bottom:16px;" id="<?php echo esc_attr($sid);?>">
        <div class="ac-docs-content" style="font-size:14px;color:var(--ac-text);line-height:1.8;">
            <?php echo ac_md_to_html($section);?>
        </div>
    </div>
    <?php endforeach;?>
<?php else:?>
    <div class="ac-empty-state">
        <span class="dashicons dashicons-media-document" style="font-size:48px;width:48px;height:48px;opacity:0.2;margin-bottom:12px;"></span>
        <p style="font-size:14px;color:var(--ac-text-secondary);"><?php esc_html_e('La documentacion no esta disponible.','alegra-connector');?></p>
        <p style="font-size:12px;color:var(--ac-text-muted);"><?php esc_html_e('Asegurate de que el archivo docs/DOCUMENTACION.md existe en el plugin.','alegra-connector');?></p>
    </div>
<?php endif;?>

<?php include __DIR__.'/footer.php'; ?>
</div>

<style>
.ac-docs-content h1 { font-size:22px;font-weight:700;color:var(--ac-text);margin:0 0 12px 0;padding-bottom:8px;border-bottom:2px solid var(--ac-primary); }
.ac-docs-content h2 { font-size:18px;font-weight:600;color:var(--ac-text);margin:20px 0 10px 0; }
.ac-docs-content h3 { font-size:15px;font-weight:600;color:var(--ac-text-secondary);margin:16px 0 8px 0; }
.ac-docs-content p { margin:0 0 10px 0; }
.ac-docs-content ul, .ac-docs-content ol { margin:0 0 12px 20px; }
.ac-docs-content li { margin:4px 0; }
.ac-docs-content code { font-family:monospace;font-size:12px;background:var(--ac-surface-alt);padding:2px 6px;border-radius:4px;color:var(--ac-primary); }
.ac-docs-content pre { background:#1e293b;color:#e2e8f0;padding:16px 20px;border-radius:8px;overflow-x:auto;font-size:12px;line-height:1.5;margin:10px 0;white-space:pre;font-family:"SF Mono","Fira Code","Cascadia Code",Consolas,monospace; }
.ac-docs-content pre code { background:none;color:inherit;padding:0;font-size:12px;white-space:pre; }
.ac-docs-content hr { border:none;border-top:1px solid var(--ac-border);margin:16px 0; }
.ac-docs-content img { max-width:100%;height:auto; }
.ac-docs-content strong { color:var(--ac-text); }
.ac-docs-content a { color:var(--ac-primary);text-decoration:none; }
.ac-docs-content a:hover { text-decoration:underline; }
.ac-doc-table { width:100%;border-collapse:collapse;margin:10px 0;font-size:13px; }
.ac-doc-table th { background:var(--ac-surface-alt);padding:8px 12px;text-align:left;font-weight:600;color:var(--ac-text-secondary);border:1px solid var(--ac-border); }
.ac-doc-table td { padding:8px 12px;border:1px solid var(--ac-border); }
</style>