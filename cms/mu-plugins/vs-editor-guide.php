<?php
/**
 * Plugin Name:  Vivid Smiles — Editor Guide
 * Description:  Rewrites the wp-admin guidance messages per page, so an editor
 *               can tell which tab, which box and which photo change which part
 *               of which page.
 * Author:       Concepcion.Work
 * Version:      0.1.0
 *
 * WHY THIS FILE EXISTS. The owner opened the home page and the about page in
 * wp-admin and could not see where to edit the images and the content. Both
 * pages were fully editable — the problem was that nothing said so in terms a
 * person could use. A photo lived under a code like drBryceMain with no hint
 * that it is the large portrait of Dr. Richardson, and the one-size orientation
 * message pointed every page at Page sections, which is empty on the handful of
 * template-driven pages where the older tabs are the live controls — and on the
 * home page the Hero tab said “These boxes are live” about boxes the site does
 * not read at all. A control that quietly does nothing is the exact failure the
 * sibling files exist to prevent; a control that works but cannot be found is
 * the same failure seen from the other side.
 *
 * WHAT IT DOES. Five rewrites, all on `acf/prepare_field`, all reading the
 * hardcoded per-page maps below, keyed by the page’s WordPress database ID:
 *
 *   1. The orientation message above the tabs becomes one of three variants —
 *      blocks-composed page (edit words in Page sections, read the Images tab
 *      guide for photos), template page (the exact list of tabs that are live
 *      HERE), or the home page (the headline area is ours, everything below is
 *      yours).
 *   2. The Hero tab message on a page whose hero is not wired (the home page)
 *      is replaced: the top area is managed by us — ask and we connect it.
 *   3. The Images tab opens with a guide naming, for every photo code on THIS
 *      page, what a visitor sees there — and whether the photo is swapped here
 *      or in Page sections these days. The code box on each filled row says to
 *      change the Image, never the code.
 *   4. The Section copy tab likewise opens with a guide mapping each row’s
 *      Section code to the part of the page it feeds, and its status.
 *   5. On pages where the visible questions moved into Page sections, the FAQ
 *      tab says so — and, where the old list still feeds what Google shows for
 *      the page, says to keep the two matching. (The per-page audits called
 *      for this one; it is the same idea as the other four.)
 *
 * WHERE THE MAPS CAME FROM. Read out of the Astro templates in
 * vivid-smiles-website/src/pages/**, cms/import/block-map.json and the live
 * GraphQL endpoint on 2026-09-01, one call site at a time, under one rule: a
 * section()/image() call outside the hasBlocks ternary is live always; one
 * inside the else-branch is dead on every blocks-composed page; a value
 * block-map.json records as moved into a block row is edited in Page sections
 * now, and the guide names the target section by its live heading. Heroes stay
 * template-rendered everywhere, so hero photo rows stay live even on
 * blocks-composed pages.
 *
 * THE GUARD. Every rewrite starts by looking the current page up in the maps.
 * A page these maps do not know — a new page an editor creates, a page
 * recreated under a new ID — is left exactly as it is and keeps today’s static
 * messages, which are written for precisely that general case. Failing back to
 * the old message is the designed behaviour, not an error.
 *
 * WP-ADMIN ONLY. Message and instruction text has no GraphQL surface, so
 * nothing here can change what the build sees. And vs-content-model.php is
 * deliberately untouched: this file hooks the same `acf/prepare_field` filter
 * at priority 20, after that file’s unlock and hide filters have run, and the
 * one field both files touch (the photo code box) is handled on disjoint
 * conditions — that file speaks only to an empty box, this one only to a
 * filled one. Load order between the two does not matter either way: both
 * files only register hooks at load time.
 */

declare( strict_types=1 );

namespace VividSmiles\EditorGuide;

/**
 * The per-page maps.
 *
 * Keys are live WordPress database IDs. `kind` picks the orientation variant:
 * 'home', 'template' (Page sections empty by design, the older tabs are live)
 * or 'blocks' (Page sections owns the page). `liveTabs` is the complete list
 * of tabs that change that page, worded for the orientation message.
 *
 * `images` rows: the photo code, what a visitor sees in that spot, and a
 * status — 'live' (swap it on the Images tab), or 'moved:<heading>' (the photo
 * is changed in Page sections now, in the section with that heading). No page
 * has an unused photo row: even a moved one is still checked by the build, so
 * the guide never invites deleting one.
 *
 * `sections` rows (where present): the same idea for the Section copy tab,
 * with one more status — 'dead', a row the design absorbed: it saves, but
 * nothing on the site reads it any more.
 */
const PAGES = [

	// ── The five template-driven pages: Page sections is empty on purpose ──

	78 => [ // Home
		'route'    => '/',
		'kind'     => 'home',
		'liveTabs' => [ 'Section copy', 'Images' ],
		'images'   => [
			[ 'slot' => 'heroBg', 'where' => 'the full-width photo of the team behind the big headline at the very top — the same photo appears again beside the membership offer lower down', 'status' => 'live' ],
			[ 'slot' => 'logoAACD', 'where' => 'the AACD logo in the scrolling “Accredited by” strip near the top', 'status' => 'live' ],
			[ 'slot' => 'logoAAID', 'where' => 'the AAID logo in the scrolling “Accredited by” strip', 'status' => 'live' ],
			[ 'slot' => 'logoFAM', 'where' => 'the Full Arch Masters logo in the scrolling “Accredited by” strip', 'status' => 'live' ],
			[ 'slot' => 'logoYomi', 'where' => 'the Yomi Robotics logo in the scrolling “Accredited by” strip', 'status' => 'live' ],
			[ 'slot' => 'logoADA', 'where' => 'the ADA logo in the scrolling “Accredited by” strip', 'status' => 'live' ],
			[ 'slot' => 'logoGoogle', 'where' => 'the Google Reviews logo in the scrolling “Accredited by” strip', 'status' => 'live' ],
			[ 'slot' => 'imgVeneers', 'where' => 'the wide photo on the big “Cosmetic & Veneers” card in the services area', 'status' => 'live' ],
			[ 'slot' => 'imgImplants', 'where' => 'the photo on the “Implants” card in the services area', 'status' => 'live' ],
			[ 'slot' => 'imgGeneral', 'where' => 'the photo on the “General Dentistry” card in the services area', 'status' => 'live' ],
			[ 'slot' => 'imgEmergency', 'where' => 'the photo on the “Emergency Care” card in the services area', 'status' => 'live' ],
			[ 'slot' => 'storyVeneersMore', 'where' => 'the photo on the first patient video card (“More than a smile”)', 'status' => 'live' ],
			[ 'slot' => 'storyVeneersConfidence', 'where' => 'the photo on the second patient video card (“Confidence, restored”)', 'status' => 'live' ],
			[ 'slot' => 'storyVeneersLength', 'where' => 'the photo on the third patient video card (“Length & balance”)', 'status' => 'live' ],
			[ 'slot' => 'notableAnnMarie', 'where' => 'the photo of Ann-Marie Muscarello in the Denver Broncos cheerleaders area', 'status' => 'live' ],
			[ 'slot' => 'notableBrittany', 'where' => 'the photo of Brittany Fanning in the Denver Broncos cheerleaders area', 'status' => 'live' ],
			[ 'slot' => 'drPortrait', 'where' => 'the tall portrait of Dr. Richardson beside the Meet the Doctor heading mid-page — also the small round photo above his name at the end of the three-step process', 'status' => 'live' ],
			[ 'slot' => 'techYomi', 'where' => 'the larger photo in the technology area — Dr. Richardson with the robotic arm', 'status' => 'live' ],
			[ 'slot' => 'techVeneerShells', 'where' => 'the smaller overlapping photo in the technology area — veneer shells on a model', 'status' => 'live' ],
		],
		'imagesNote' => 'Not on this list: the scrolling smile photos in the dark area lower down — add or remove those under Practice Settings → Smile gallery in the left menu.',
		'sections' => [
			[ 'id' => 'services', 'where' => 'the small line, heading and paragraph above the four service cards near the top', 'status' => 'live' ],
			[ 'id' => 'stories', 'where' => 'the small line, heading and paragraph above the three patient video cards', 'status' => 'live' ],
			[ 'id' => 'notable', 'where' => 'the small line, heading and paragraph above the two Denver Broncos cheerleader photos', 'status' => 'live' ],
			[ 'id' => 'doctor', 'where' => 'the small line, heading and lead paragraph of the Meet the Doctor area mid-page', 'status' => 'live' ],
			[ 'id' => 'gallery', 'where' => 'the heading and paragraph of the dark area with the scrolling smile photos', 'status' => 'live' ],
			[ 'id' => 'technology', 'where' => 'the small line, heading and lead paragraph of the technology area with the robot photo', 'status' => 'live' ],
		],
	],

	79 => [ // About Us
		'route'    => '/about-us/',
		'kind'     => 'template',
		'liveTabs' => [ 'Hero', 'Section copy', 'Images', 'Bottom of page (the consultation invite)' ],
		'images'   => [
			[ 'slot' => 'heroTeam', 'where' => 'the photo beside the headline at the very top — Dr. Annie and Dr. Bryce together', 'status' => 'live' ],
			[ 'slot' => 'storyOffice', 'where' => 'the photo in the Our Story area — the neon smile sign inside the office', 'status' => 'live' ],
			[ 'slot' => 'drBryceMain', 'where' => 'the large portrait of Dr. Richardson in the Meet the Doctors area', 'status' => 'live' ],
			[ 'slot' => 'drBryceInset', 'where' => 'the small photo overlapping Dr. Richardson’s portrait — him working in surgical loupes', 'status' => 'live' ],
			[ 'slot' => 'drAnnieMain', 'where' => 'the large portrait of Dr. Annie in the Meet the Doctors area', 'status' => 'live' ],
			[ 'slot' => 'drAnnieInset', 'where' => 'the small photo overlapping Dr. Annie’s portrait', 'status' => 'live' ],
			[ 'slot' => 'teamSara', 'where' => 'Sara’s photo (Office Manager) in the team area', 'status' => 'live' ],
			[ 'slot' => 'teamKt', 'where' => 'KT’s photo (Patient Care & Office Coordinator) in the team area', 'status' => 'live' ],
			[ 'slot' => 'teamCarol', 'where' => 'Carol’s photo (Patient Coordinator) in the team area', 'status' => 'live' ],
			[ 'slot' => 'teamMandy', 'where' => 'Mandy’s photo (Dental Hygienist) in the team area', 'status' => 'live' ],
			[ 'slot' => 'teamSammie', 'where' => 'Sammie’s photo (Dental Hygienist) in the team area', 'status' => 'live' ],
			[ 'slot' => 'teamLinh', 'where' => 'Linh’s photo (Dental Assistant) in the team area', 'status' => 'live' ],
			[ 'slot' => 'teamTina', 'where' => 'Tina’s photo (Dental Assistant) in the team area', 'status' => 'live' ],
			[ 'slot' => 'teamKnox', 'where' => 'Knox’s photo (the dog, Director of Smiles) in the team area', 'status' => 'live' ],
			[ 'slot' => 'teamBirdie', 'where' => 'Birdie’s photo (the dog, Chief Comfort Officer) in the team area', 'status' => 'live' ],
			[ 'slot' => 'yomiImg', 'where' => 'the photo in the technology area — a treatment room', 'status' => 'live' ],
			[ 'slot' => 'aacdLogo', 'where' => 'the AACD logo card in the credentials area near the bottom', 'status' => 'live' ],
			[ 'slot' => 'aaidLogo', 'where' => 'the AAID logo card in the credentials area', 'status' => 'live' ],
			[ 'slot' => 'famLogo', 'where' => 'the Full Arch Masters logo card in the credentials area', 'status' => 'live' ],
			[ 'slot' => 'iuLogo', 'where' => 'the Indiana University logo card in the credentials area', 'status' => 'live' ],
			[ 'slot' => 'yomiLogo', 'where' => 'the Yomi Robotics logo card in the credentials area', 'status' => 'live' ],
			[ 'slot' => 'adaLogo', 'where' => 'the ADA logo card in the credentials area', 'status' => 'live' ],
			[ 'slot' => 'googleLogo', 'where' => 'the Google Reviews logo card in the credentials area', 'status' => 'live' ],
			[ 'slot' => 'vsLogo', 'where' => 'the Vivid Smiles logo card in the credentials area', 'status' => 'live' ],
		],
		'sections' => [
			[ 'id' => 'story', 'where' => 'the heading and first paragraph beside the neon-sign photo in Our Story', 'status' => 'live' ],
			[ 'id' => 'doctors', 'where' => 'Dr. Richardson’s name heading and his short intro line in Meet the Doctors (Dr. Annie’s wording is managed by us)', 'status' => 'live' ],
			[ 'id' => 'technology', 'where' => 'the heading and lead paragraph beside the treatment-room photo in the technology area', 'status' => 'live' ],
			[ 'id' => 'voices', 'where' => 'the heading and paragraph above the scrolling patient reviews', 'status' => 'live' ],
		],
	],

	90 => [ // Patient Testimonials
		'route'    => '/patient-testimonials/',
		'kind'     => 'template',
		'liveTabs' => [ 'Hero', 'Section copy', 'Images', 'Bottom of page (the consultation invite)' ],
		'images'   => [
			[ 'slot' => 'heroImg', 'where' => 'the photo beside the headline at the very top — the team walking outside the office', 'status' => 'live' ],
			[ 'slot' => 'veneerMore', 'where' => 'the tall photo on the first veneer video card (“More than a smile”)', 'status' => 'live' ],
			[ 'slot' => 'veneerConfidence', 'where' => 'the tall photo on the “Confidence, restored” veneer video card', 'status' => 'live' ],
			[ 'slot' => 'veneerLength', 'where' => 'the tall photo on the “Length & balance” veneer video card', 'status' => 'live' ],
			[ 'slot' => 'veneerFromTo', 'where' => 'the tall photo on the “From veneers to All-on-X” video card', 'status' => 'live' ],
			[ 'slot' => 'veneerBonding', 'where' => 'the tall photo on the “Bonding or veneers?” video card', 'status' => 'live' ],
			[ 'slot' => 'veneerAnneMarie', 'where' => 'the tall photo on Anne Marie’s video card', 'status' => 'live' ],
			[ 'slot' => 'veneerPrep', 'where' => 'the tall photo on the “No-prep or minimal-prep?” video card', 'status' => 'live' ],
			[ 'slot' => 'storyJames', 'where' => 'the photo on James’s video card in the patient stories area', 'status' => 'live' ],
			[ 'slot' => 'storyChanel', 'where' => 'the photo on Chanel’s video card in the patient stories area', 'status' => 'live' ],
			[ 'slot' => 'storyJosh', 'where' => 'the photo on Josh’s video card in the patient stories area', 'status' => 'live' ],
			[ 'slot' => 'featuredImg', 'where' => 'the large photo on Wayne’s featured story — a full-arch restoration held in a gloved hand', 'status' => 'live' ],
		],
		'sections' => [
			[ 'id' => 'veneer-stories', 'where' => 'the small line, heading and paragraph above the seven tall veneer video cards', 'status' => 'live' ],
			[ 'id' => 'stories', 'where' => 'the small line, heading and paragraph above the James / Chanel / Josh video cards', 'status' => 'live' ],
			[ 'id' => 'featured-story', 'where' => 'the small line, heading and the quote paragraph beside Wayne’s featured video', 'status' => 'live' ],
			[ 'id' => 'reviews', 'where' => 'the small line, heading and paragraph above the scrolling Google reviews', 'status' => 'live' ],
		],
	],

	94 => [ // Smile Gallery
		'route'    => '/smile-gallery/',
		'kind'     => 'template',
		'liveTabs' => [ 'Hero', 'Section copy', 'Images', 'Bottom of page (the consultation invite)' ],
		'images'   => [
			[ 'slot' => 'heroImg', 'where' => 'the photo beside the headline at the very top — a close-up of a patient smiling', 'status' => 'live' ],
		],
		'imagesNote' => 'Not on this list: the grid of smile photos itself — add, remove and reorder those under Practice Settings → Smile gallery in the left menu.',
		'sections' => [
			[ 'id' => 'gallery', 'where' => 'the small line, heading and paragraph above the grid of smile photos', 'status' => 'live' ],
		],
	],

	83 => [ // Dental Membership Plan
		'route'    => '/dental-membership-plan/',
		'kind'     => 'template',
		'liveTabs' => [ 'Hero', 'Section copy', 'Images', 'Bottom of page (the booking-strip sentence only)' ],
		'images'   => [
			[ 'slot' => 'heroImg', 'where' => 'the photo beside the headline at the very top — the whole team', 'status' => 'live' ],
			[ 'slot' => 'step1Img', 'where' => 'the small square photo on step one (“Enroll”) in the How it works card', 'status' => 'live' ],
			[ 'slot' => 'step2Img', 'where' => 'the small square photo on step two (“Come in”) in the How it works card', 'status' => 'live' ],
			[ 'slot' => 'step3Img', 'where' => 'the small square photo on step three (“Renew”) in the How it works card', 'status' => 'live' ],
		],
		'sections' => [
			[ 'id' => 'story', 'where' => 'the heading and first paragraph of the dark “Why we built it” area', 'status' => 'live' ],
			[ 'id' => 'how-it-works', 'where' => 'the heading and paragraph above the three-step card', 'status' => 'live' ],
			[ 'id' => 'whats-included', 'where' => 'the centered heading and paragraph above the big $500 plan card', 'status' => 'live' ],
			[ 'id' => 'faq', 'where' => 'the heading above the questions-and-answers list (the questions themselves are managed by us)', 'status' => 'live' ],
		],
	],

	// ── Cosmetic dentistry: seven blocks-composed pages ──

	82 => [ // Cosmetic Dentistry hub
		'route'    => '/cosmetic-dentistry/',
		'kind'     => 'blocks',
		'liveTabs' => [ 'Hero', 'Images (the top photo)', 'Page sections', 'Bottom of page' ],
		'faq'      => 'mirror',
		'images'   => [
			[ 'slot' => 'imgHero', 'where' => 'the big photo to the right of the headline at the very top of the page — currently a smiling woman at the neon smile wall', 'status' => 'live' ],
			[ 'slot' => 'imgVeneers', 'where' => 'the photo on the Porcelain Veneers card in the services grid mid-page', 'status' => 'moved:Cosmetic dentistry services at Vivid Smiles.' ],
			[ 'slot' => 'imgAligners', 'where' => 'the photo on the Clear Aligners card in the services grid mid-page', 'status' => 'moved:Cosmetic dentistry services at Vivid Smiles.' ],
			[ 'slot' => 'imgWhitening', 'where' => 'the photo on the Teeth Whitening card in the services grid mid-page', 'status' => 'moved:Cosmetic dentistry services at Vivid Smiles.' ],
			[ 'slot' => 'imgGum', 'where' => 'the photo on the Gum Contouring card in the services grid mid-page', 'status' => 'moved:Cosmetic dentistry services at Vivid Smiles.' ],
			[ 'slot' => 'imgMakeover', 'where' => 'the photo on the Smile Makeover card in the services grid mid-page', 'status' => 'moved:Cosmetic dentistry services at Vivid Smiles.' ],
			[ 'slot' => 'imgFullMouth', 'where' => 'the photo on the Full Mouth Rehabilitation card in the services grid mid-page', 'status' => 'moved:Cosmetic dentistry services at Vivid Smiles.' ],
			[ 'slot' => 'imgDsd', 'where' => 'the smile close-up next to “Preview your results before you commit” in the dark technology section', 'status' => 'moved:Better outcomes, fewer appointments.' ],
			[ 'slot' => 'imgDrBryce', 'where' => 'the large portrait of Dr. Bryce Richardson in the meet-the-doctor section', 'status' => 'moved:Meet Dr. Bryce Richardson — education, training & expertise.' ],
			[ 'slot' => 'imgDrAnnie', 'where' => 'the small round headshot of Dr. Annie next to “Also at the practice” in the meet-the-doctor section', 'status' => 'moved:Meet Dr. Bryce Richardson — education, training & expertise.' ],
		],
	],

	98 => [ // Clear Aligners
		'route'    => '/cosmetic-dentistry/clear-aligners/',
		'kind'     => 'blocks',
		'liveTabs' => [ 'Hero', 'Images (the top photo)', 'Page sections', 'Bottom of page' ],
		'faq'      => 'mirror',
		'images'   => [
			[ 'slot' => 'heroImg', 'where' => 'the big photo next to the headline at the very top of the page — currently a patient holding a clear aligner tray', 'status' => 'live' ],
			[ 'slot' => 'whatImg', 'where' => 'the photo beside the “What Are Clear Aligners?” heading — currently a smiling woman by a mountain lake', 'status' => 'moved:What Are Clear Aligners?' ],
			[ 'slot' => 'naturalImg', 'where' => 'the photo in the “Who Is a Good Candidate for Clear Aligners?” section — currently a smiling woman photographed indoors', 'status' => 'moved:Who Is a Good Candidate for Clear Aligners?' ],
		],
	],

	99 => [ // Full Mouth Rehabilitation
		'route'    => '/cosmetic-dentistry/full-mouth-rehabilitation/',
		'kind'     => 'blocks',
		'liveTabs' => [ 'Hero', 'Images (the top photo)', 'Page sections', 'Bottom of page' ],
		'faq'      => 'mirror',
		'images'   => [
			[ 'slot' => 'heroImg', 'where' => 'the big photo next to the headline at the very top of the page — currently a smiling woman at the office', 'status' => 'live' ],
			[ 'slot' => 'whatImg', 'where' => 'the photo beside the “What is Full-Mouth Rehabilitation?” heading — currently a full-arch restoration held on a dental model', 'status' => 'moved:What is Full-Mouth Rehabilitation?' ],
			[ 'slot' => 'designImg', 'where' => 'the photo in the “Digital Smile Design and Treatment Planning” section — currently a smile in profile against black', 'status' => 'moved:Digital Smile Design and Treatment Planning' ],
		],
	],

	100 => [ // Gum Contouring
		'route'    => '/cosmetic-dentistry/gum-contouring/',
		'kind'     => 'blocks',
		'liveTabs' => [ 'Hero', 'Images (the top photo)', 'Page sections', 'Bottom of page' ],
		'faq'      => 'mirror',
		'images'   => [
			[ 'slot' => 'heroImg', 'where' => 'the big photo next to the headline at the very top of the page — currently a smiling woman outdoors among Colorado pines', 'status' => 'live' ],
			[ 'slot' => 'whatImg', 'where' => 'the photo beside the “What Is Gum Contouring?” heading — currently a close-up of a smile showing the gum line', 'status' => 'moved:What Is Gum Contouring?' ],
			[ 'slot' => 'gumlineImg', 'where' => 'the photo in the laser-technology section — currently a side-lit close-up of lips and upper teeth', 'status' => 'moved:How Laser Technology Makes Gum Contouring Gentle and Precise' ],
			[ 'slot' => 'resultsImg', 'where' => 'the photo in the “Gum Contouring Results: What to Expect” section — currently a smiling woman inside the office', 'status' => 'moved:Gum Contouring Results: What to Expect' ],
		],
	],

	101 => [ // Porcelain Veneers
		'route'    => '/cosmetic-dentistry/porcelain-veneers/',
		'kind'     => 'blocks',
		'liveTabs' => [ 'Hero', 'Images (the top photo)', 'Page sections', 'Bottom of page' ],
		'faq'      => 'mirror',
		'images'   => [
			[ 'slot' => 'heroImg', 'where' => 'the big photo next to the headline at the very top of the page — currently a patient in front of the practice neon sign', 'status' => 'live' ],
			[ 'slot' => 'whatImg', 'where' => 'the photo beside the “What Are Porcelain Veneers?” heading — currently veneer shells seated on a dental model', 'status' => 'moved:What Are Porcelain Veneers?' ],
			[ 'slot' => 'naturalImg', 'where' => 'the photo in the “Achieving Natural-Looking Results” section — currently a close-up of finished porcelain veneers', 'status' => 'moved:Achieving Natural-Looking Results' ],
		],
	],

	102 => [ // Smile Makeover
		'route'    => '/cosmetic-dentistry/smile-makeover/',
		'kind'     => 'blocks',
		'liveTabs' => [ 'Hero', 'Images (the top photo)', 'Page sections', 'Bottom of page' ],
		'faq'      => 'mirror',
		'images'   => [
			[ 'slot' => 'heroImg', 'where' => 'the big photo next to the headline at the very top of the page — currently a smiling woman against a dark studio background', 'status' => 'live' ],
			[ 'slot' => 'whatImg', 'where' => 'the photo beside the “What Is a Smile Makeover?” heading — currently a smile at a three-quarter angle against black', 'status' => 'moved:What Is a Smile Makeover?' ],
			[ 'slot' => 'editorialImg', 'where' => 'the photo in the makeover-process section — currently an editorial smile close-up with berry lipstick', 'status' => 'moved:Our Smile Makeover Process: Digital Design to Final Result' ],
		],
	],

	103 => [ // Teeth Whitening
		'route'           => '/cosmetic-dentistry/teeth-whitening/',
		'kind'            => 'blocks',
		'liveTabs'        => [ 'Hero', 'Images (the top photo)', 'Page sections', 'Bottom of page' ],
		'faq'             => 'mirror',
		// The heading is the live Page sections row, read from the endpoint on
		// 2026-09-01 — the four whitening prices are a price list of their own.
		'orientationNote' => 'The four whitening prices are edited in Page sections too — open the row headed “Teeth Whitening Pricing in Parker, Colorado”.',
		'images'          => [
			[ 'slot' => 'heroImg', 'where' => 'the big photo next to the headline at the very top of the page — currently a smiling man at the neon smile wall', 'status' => 'live' ],
			[ 'slot' => 'whatImg', 'where' => 'the photo beside the “What Is KöR Teeth Whitening?” heading — currently a close-up of a natural smile in daylight', 'status' => 'moved:What Is KöR Teeth Whitening?' ],
			[ 'slot' => 'naturalImg', 'where' => 'the photo in the “Who Is a Good Candidate for Professional Teeth Whitening?” section — currently a smiling woman photographed indoors', 'status' => 'moved:Who Is a Good Candidate for Professional Teeth Whitening?' ],
		],
	],

	// ── Implant dentistry: six blocks-composed pages ──

	87 => [ // Implant Dentistry hub
		'route'    => '/implant-dentistry/',
		'kind'     => 'blocks',
		'liveTabs' => [ 'Hero', 'Images (the top photo)', 'Page sections', 'Bottom of page' ],
		'faq'      => 'mirror',
		'images'   => [
			[ 'slot' => 'imgHero', 'where' => 'the large photo beside the headline at the very top of the page — an implant-supported restoration held on a dental model', 'status' => 'live' ],
			[ 'slot' => 'imgWhat', 'where' => 'the photo on the left of the “What are dental implants?” section — a smiling woman at the Vivid Smiles office', 'status' => 'moved:What are dental implants?' ],
			[ 'slot' => 'imgSingleTooth', 'where' => 'the photo on the first of the five clickable service tiles (“Single Tooth Implants”), below the pricing table', 'status' => 'moved:Service tiles' ],
			[ 'slot' => 'imgFullMouth', 'where' => 'the photo on the “Full Mouth Implants” service tile — a smiling woman beside a mountain lake', 'status' => 'moved:Service tiles' ],
			[ 'slot' => 'imgAllOn4', 'where' => 'the photo on the “All-on-4 Single Arch” service tile — a smiling man at the neon smile wall', 'status' => 'moved:Service tiles' ],
			[ 'slot' => 'imgBoneGrafting', 'where' => 'the photo on the “Bone Grafting” service tile — a smiling woman inside the office', 'status' => 'moved:Service tiles' ],
			[ 'slot' => 'imgSinusLift', 'where' => 'the photo on the “Sinus Lift” service tile — a treatment room at the practice', 'status' => 'moved:Service tiles' ],
			[ 'slot' => 'imgDrBryce', 'where' => 'the portrait of Dr. Bryce Richardson in the meet-the-doctor area mid-page', 'status' => 'moved:Dr. Richardson’s implant training and experience.' ],
		],
	],

	104 => [ // All-on-4 Single Arch
		'route'    => '/implant-dentistry/all-on-4-single-arch/',
		'kind'     => 'blocks',
		'liveTabs' => [ 'Hero', 'Images (the top photo)', 'Page sections', 'Bottom of page' ],
		'faq'      => 'mirror',
		'images'   => [
			[ 'slot' => 'heroImg', 'where' => 'the large photo beside the headline at the very top of the page — a smiling woman at the Vivid Smiles office', 'status' => 'live' ],
			[ 'slot' => 'whatImg', 'where' => 'the photo on the left of the “What Are All-on-4 Single Arch Implants?” section — a full set of teeth on a dental model, held in a gloved hand', 'status' => 'moved:What Are All-on-4 Single Arch Implants?' ],
			[ 'slot' => 'roboticsImg', 'where' => 'the photo of Dr. Richardson using the robotic arm, in the section about robotic placement', 'status' => 'moved:How Robotic All-on-4 Placement Works at Vivid Smiles' ],
			[ 'slot' => 'candidacyImg', 'where' => 'the photo on the right of the who-is-a-candidate section — a smiling woman outdoors in the foothills', 'status' => 'moved:Who Is a Good Candidate for Single Arch All-on-4?' ],
		],
	],

	105 => [ // Bone Grafting
		'route'    => '/implant-dentistry/bone-grafting/',
		'kind'     => 'blocks',
		'liveTabs' => [ 'Hero', 'Images (the top photo)', 'Page sections', 'Bottom of page' ],
		'faq'      => 'mirror',
		'images'   => [
			[ 'slot' => 'heroImg', 'where' => 'the large photo beside the headline at the very top of the page — a smiling man at the neon smile wall', 'status' => 'live' ],
			[ 'slot' => 'whatImg', 'where' => 'the illustration on the left of the “What Is Bone Grafting?” section — a cross-section showing graft material under a membrane', 'status' => 'moved:What Is Bone Grafting?' ],
			[ 'slot' => 'implantsImg', 'where' => 'the smaller framed photo in the grafting-and-implants area mid-page — an implant restoration on a dental model', 'status' => 'moved:Bone Grafting and Dental Implants' ],
			[ 'slot' => 'candidacyImg', 'where' => 'the photo on the right of the who-needs-it section — a woman beside a stone wall on a bright afternoon', 'status' => 'moved:Who Needs Bone Grafting?' ],
		],
	],

	106 => [ // Full Mouth Dental Implants
		'route'    => '/implant-dentistry/full-mouth-dental-implants/',
		'kind'     => 'blocks',
		'liveTabs' => [ 'Hero', 'Images (the top photo)', 'Page sections', 'Bottom of page' ],
		'faq'      => 'mirror',
		'images'   => [
			[ 'slot' => 'heroImg', 'where' => 'the large photo beside the headline at the very top of the page — a smiling woman inside the Vivid Smiles office', 'status' => 'live' ],
			[ 'slot' => 'whatImg', 'where' => 'the photo on the left of the “What Are Full Mouth Dental Implants?” section — a full set of teeth on a dental model, held in a gloved hand', 'status' => 'moved:What Are Full Mouth Dental Implants?' ],
			[ 'slot' => 'roboticsImg', 'where' => 'the photo of Dr. Richardson using the robotic arm, in the section about robotic placement', 'status' => 'moved:Robotic Implant Placement: Precision Technology' ],
			[ 'slot' => 'candidacyImg', 'where' => 'the photo on the right of the who-is-a-candidate section — a woman seated in tall grass above a Colorado lake', 'status' => 'moved:Who Is a Candidate for Full Mouth Implants?' ],
		],
	],

	107 => [ // Single Tooth Dental Implants
		'route'    => '/implant-dentistry/single-tooth-dental-implants/',
		'kind'     => 'blocks',
		'liveTabs' => [ 'Hero', 'Images (the top photo)', 'Page sections', 'Bottom of page' ],
		'faq'      => 'mirror',
		'images'   => [
			[ 'slot' => 'heroImg', 'where' => 'the large photo beside the headline at the very top of the page — a smiling woman photographed indoors', 'status' => 'live' ],
			[ 'slot' => 'whatImg', 'where' => 'the photo on the left of the “Why Choose a Single Tooth Implant?” section — a close-up of an implant restoration on a dental model', 'status' => 'moved:Why Choose a Single Tooth Implant?' ],
			[ 'slot' => 'candidacyImg', 'where' => 'the photo on the right of the who-is-a-candidate section — a woman among pines on a Colorado hillside', 'status' => 'moved:Who Is a Candidate for Single Tooth Implants?' ],
		],
	],

	108 => [ // Sinus Lift
		'route'    => '/implant-dentistry/sinus-lift/',
		'kind'     => 'blocks',
		'liveTabs' => [ 'Hero', 'Images (the top photo)', 'Page sections', 'Bottom of page' ],
		'faq'      => 'mirror',
		'images'   => [
			[ 'slot' => 'heroImg', 'where' => 'the large photo beside the headline at the very top of the page — a smiling woman outdoors in the foothills', 'status' => 'live' ],
			[ 'slot' => 'drRichardsonImg', 'where' => 'the portrait of Dr. Bryce Richardson in the first section under the headline area (why choose Vivid Smiles)', 'status' => 'moved:Why Choose Vivid Smiles for Your Sinus Lift in Parker, CO' ],
			[ 'slot' => 'whatImg', 'where' => 'the illustration on the left of the “What Is a Sinus Lift?” section — a cross-section of graft material beneath the lifted sinus membrane', 'status' => 'moved:What Is a Sinus Lift?' ],
			[ 'slot' => 'yomiImg', 'where' => 'the photo of Dr. Richardson using the robotic arm, in the technology section lower on the page', 'status' => 'moved:Advanced Technology for Predictable Outcomes' ],
		],
	],

	// ── The rest: seven blocks-composed pages with mixed older tabs ──

	85 => [ // General Dentistry
		'route'    => '/general-dentistry/',
		'kind'     => 'blocks',
		'liveTabs' => [ 'Hero', 'Images (the top photo)', 'Page sections', 'Bottom of page' ],
		'faq'      => 'mirror',
		'images'   => [
			[ 'slot' => 'imgHero', 'where' => 'the big photo to the right of the headline at the very top of the page — a hygienist polishing a patient’s teeth', 'status' => 'live' ],
			[ 'slot' => 'imgPreventive', 'where' => 'the photo on the “Preventive Care & Cleanings” card in the four-card services row', 'status' => 'moved:General dentistry services at Vivid Smiles.' ],
			[ 'slot' => 'imgRestorative', 'where' => 'the photo on the “Fillings, Crowns & Bridges” card in that same row', 'status' => 'moved:General dentistry services at Vivid Smiles.' ],
			[ 'slot' => 'imgPeriodontal', 'where' => 'the photo on the “Periodontal Care” card in that same row', 'status' => 'moved:General dentistry services at Vivid Smiles.' ],
			[ 'slot' => 'imgEmergency', 'where' => 'the photo on the “Emergency Dental Care” card in that same row', 'status' => 'moved:General dentistry services at Vivid Smiles.' ],
			[ 'slot' => 'imgFirstVisit', 'where' => 'the photo of the practice’s etched entry doors beside the first-visit copy', 'status' => 'moved:What to expect during your first general dentistry visit.' ],
			[ 'slot' => 'imgGateway', 'where' => 'the portrait at the neon smile wall beside the cosmetic-care copy', 'status' => 'moved:General dentistry as a gateway to cosmetic care.' ],
			[ 'slot' => 'imgDoctors', 'where' => 'the photo of both doctors in the meet-the-doctors area', 'status' => 'moved:Meet Dr. Richardson and Dr. Annie.' ],
		],
		'sections' => [
			[ 'id' => 'why', 'where' => 'the dark technology-and-stats area under the headline', 'status' => 'moved:Technology-forward, family-focused care.' ],
			[ 'id' => 'services', 'where' => 'the intro above the four service cards', 'status' => 'moved:General dentistry services at Vivid Smiles.' ],
			[ 'id' => 'first-visit', 'where' => 'the first-visit area with the entry-doors photo', 'status' => 'moved:What to expect during your first general dentistry visit.' ],
			[ 'id' => 'gateway', 'where' => 'the cosmetic-care area with the neon-wall portrait', 'status' => 'moved:General dentistry as a gateway to cosmetic care.' ],
			[ 'id' => 'trust', 'where' => 'the dark “Why Parker trusts us” quote area mid-page', 'status' => 'dead' ],
			[ 'id' => 'doctors', 'where' => 'the meet-the-doctors area', 'status' => 'moved:Meet Dr. Richardson and Dr. Annie.' ],
			[ 'id' => 'payment', 'where' => 'the insurance-and-membership area', 'status' => 'dead' ],
			[ 'id' => 'area', 'where' => 'the map-and-address area', 'status' => 'dead' ],
			[ 'id' => 'faq', 'where' => 'the intro above the questions-and-answers list', 'status' => 'moved:Frequently asked questions about general dentistry in Parker, CO.' ],
		],
	],

	84 => [ // Emergency Dental Care
		'route'    => '/emergency-dentistry/',
		'kind'     => 'blocks',
		'liveTabs' => [ 'Hero', 'Images (the top photo)', 'Page sections', 'Section copy (only the message-form heading row)', 'Bottom of page (the booking-strip sentence only)' ],
		'faq'      => 'inert',
		'images'   => [
			[ 'slot' => 'imgHero', 'where' => 'the big photo to the right of the headline at the very top of the page — a smiling woman in a warm interior', 'status' => 'live' ],
			[ 'slot' => 'imgHow', 'where' => 'the photo of a dentist and assistant treating a patient, beside the how-we-handle-emergencies copy', 'status' => 'moved:How Vivid Smiles handles emergency dental cases.' ],
		],
		'sections' => [
			[ 'id' => 'when', 'where' => 'the dark area under the headline with the three number cards', 'status' => 'moved:When to seek emergency dental care.' ],
			[ 'id' => 'protocol', 'where' => 'the “In case of” scenario cards', 'status' => 'dead' ],
			[ 'id' => 'how', 'where' => 'the area with the treatment photo mid-page', 'status' => 'moved:How Vivid Smiles handles emergency dental cases.' ],
			[ 'id' => 'emergencies', 'where' => 'the checklist of common emergencies', 'status' => 'moved:Common dental emergencies.' ],
			[ 'id' => 'why-us', 'where' => 'the dark “Why choose Vivid Smiles” quote area', 'status' => 'dead' ],
			[ 'id' => 'area', 'where' => 'the map-and-address area', 'status' => 'dead' ],
			[ 'id' => 'prevent', 'where' => 'the preventing-emergencies area near the bottom', 'status' => 'dead' ],
			[ 'id' => 'faq', 'where' => 'the intro above the questions-and-answers list', 'status' => 'moved:Dental emergency FAQs.' ],
			[ 'id' => 'contact-form', 'where' => 'the heading beside the message form at the very bottom of the page (only the Heading box changes the page)', 'status' => 'live' ],
		],
	],

	93 => [ // Services
		'route'    => '/services/',
		'kind'     => 'blocks',
		'liveTabs' => [
			'Page sections (the three photo grids and their intros)',
			'Section copy (only the “process” and “faq” rows)',
			'FAQ (this page’s questions and answers are edited on that tab, not in Page sections)',
			'Bottom of page (the consultation invite)',
			'Hero (the headline box only — invisible on the page, it speaks to search engines and screen readers)',
		],
		'images'   => [
			[ 'slot' => 'imgVeneers', 'where' => 'the photo on the “Porcelain Veneers” card in the cosmetic grid', 'status' => 'moved:Where artistry meets esthetic dentistry.' ],
			[ 'slot' => 'imgSmileMakeover', 'where' => 'the photo on the “Smile Makeover” card in the cosmetic grid', 'status' => 'moved:Where artistry meets esthetic dentistry.' ],
			[ 'slot' => 'imgClearAligners', 'where' => 'the photo on the “Clear Aligners” card in the cosmetic grid', 'status' => 'moved:Where artistry meets esthetic dentistry.' ],
			[ 'slot' => 'imgWhitening', 'where' => 'the photo on the “Teeth Whitening” card in the cosmetic grid', 'status' => 'moved:Where artistry meets esthetic dentistry.' ],
			[ 'slot' => 'imgGumContouring', 'where' => 'the photo on the “Gum Contouring” card in the cosmetic grid', 'status' => 'moved:Where artistry meets esthetic dentistry.' ],
			[ 'slot' => 'imgFullMouthRehab', 'where' => 'the photo on the “Full Mouth Rehabilitation” card in the cosmetic grid', 'status' => 'moved:Where artistry meets esthetic dentistry.' ],
			[ 'slot' => 'imgSingleImplant', 'where' => 'the photo on the “Single Tooth Implant” card in the implant grid', 'status' => 'moved:Replace what’s missing with sub-millimeter accuracy.' ],
			[ 'slot' => 'imgFullMouthImplants', 'where' => 'the photo on the “Full Mouth Implants” card in the implant grid', 'status' => 'moved:Replace what’s missing with sub-millimeter accuracy.' ],
			[ 'slot' => 'imgAllOn4', 'where' => 'the photo on the “All-on-4 Single Arch” card in the implant grid', 'status' => 'moved:Replace what’s missing with sub-millimeter accuracy.' ],
			[ 'slot' => 'imgBoneGraft', 'where' => 'the photo on the “Bone Grafting” card in the implant grid', 'status' => 'moved:Replace what’s missing with sub-millimeter accuracy.' ],
			[ 'slot' => 'imgSinusLift', 'where' => 'the photo on the “Sinus Lift” card in the implant grid', 'status' => 'moved:Replace what’s missing with sub-millimeter accuracy.' ],
			[ 'slot' => 'imgGeneral', 'where' => 'the photo on the “General Dentistry” card in the everyday-care pair', 'status' => 'moved:When life happens, we’re here.' ],
			[ 'slot' => 'imgEmergency', 'where' => 'the photo on the “Emergency Dentistry” card in the everyday-care pair', 'status' => 'moved:When life happens, we’re here.' ],
		],
		'sections' => [
			[ 'id' => 'cosmetic', 'where' => 'the intro above the cosmetic services grid at the top', 'status' => 'moved:Where artistry meets esthetic dentistry.' ],
			[ 'id' => 'implants', 'where' => 'the intro above the implant services grid', 'status' => 'moved:Replace what’s missing with sub-millimeter accuracy.' ],
			[ 'id' => 'everyday', 'where' => 'the intro above the everyday-care pair', 'status' => 'moved:When life happens, we’re here.' ],
			[ 'id' => 'process', 'where' => 'the intro above the three how-we-work steps (the steps themselves are part of the design)', 'status' => 'live' ],
			[ 'id' => 'faq', 'where' => 'the intro above the questions-and-answers list', 'status' => 'live' ],
		],
	],

	88 => [ // New Patients
		'route'    => '/new-patients/',
		'kind'     => 'blocks',
		'liveTabs' => [ 'Hero', 'Images (the top photo)', 'Page sections', 'Section copy (only the “membership” row)', 'Bottom of page (the consultation invite)' ],
		'faq'      => 'inert',
		'images'   => [
			[ 'slot' => 'heroImg', 'where' => 'the big photo to the right of the headline at the very top — the team walking outside the office', 'status' => 'live' ],
			[ 'slot' => 'imgCosmetic', 'where' => 'the photo on the “Cosmetic Dentistry” card in the four-card services row', 'status' => 'moved:Care that fits every chapter of your smile.' ],
			[ 'slot' => 'imgImplant', 'where' => 'the photo on the “Implant Dentistry” card in that same row', 'status' => 'moved:Care that fits every chapter of your smile.' ],
			[ 'slot' => 'imgGeneral', 'where' => 'the photo on the “General Dentistry” card in that same row', 'status' => 'moved:Care that fits every chapter of your smile.' ],
			[ 'slot' => 'imgEmergency', 'where' => 'the photo on the “Emergency Dentistry” card in that same row', 'status' => 'moved:Care that fits every chapter of your smile.' ],
		],
		'sections' => [
			[ 'id' => 'first-visit', 'where' => 'the intro above the six numbered first-visit steps', 'status' => 'moved:Your first visit, step by step.' ],
			[ 'id' => 'services', 'where' => 'the intro above the four service cards', 'status' => 'moved:Care that fits every chapter of your smile.' ],
			[ 'id' => 'reviews', 'where' => 'the heading above the sliding patient-reviews strip', 'status' => 'dead' ],
			[ 'id' => 'faq', 'where' => 'the heading above the questions-and-answers list', 'status' => 'moved:Frequently asked questions.' ],
			[ 'id' => 'membership', 'where' => 'the no-insurance membership panel near the bottom (the small line and the paragraph; the bold line is part of the design)', 'status' => 'live' ],
		],
	],

	89 => [ // Our Office
		'route'    => '/our-office/',
		'kind'     => 'blocks',
		'liveTabs' => [ 'Hero', 'Images (all nine photos on this page are edited there)', 'Section copy (the “tour”, “technology” and “expect” rows)', 'Page sections (the map area and the questions list)', 'Bottom of page (the consultation invite)' ],
		'faq'      => 'inert',
		'images'   => [
			[ 'slot' => 'heroPrimary', 'where' => 'the big photo at the top of the page — the waiting-room lounge', 'status' => 'live' ],
			[ 'slot' => 'heroInset', 'where' => 'the small photo overlapping the big one at the top — the entry doors with the logo', 'status' => 'live' ],
			[ 'slot' => 'bentoA', 'where' => 'the largest tile in the photo tour grid — the reception desk', 'status' => 'live' ],
			[ 'slot' => 'bentoB', 'where' => 'the tall tile in the photo tour grid — a treatment room', 'status' => 'live' ],
			[ 'slot' => 'bentoC', 'where' => 'the tile in the photo tour grid with the glowing neon sign', 'status' => 'live' ],
			[ 'slot' => 'bentoD', 'where' => 'the tile in the photo tour grid with both doctors in the hallway', 'status' => 'live' ],
			[ 'slot' => 'bentoE', 'where' => 'the tile in the photo tour grid of a patient being welcomed at the desk', 'status' => 'live' ],
			[ 'slot' => 'techPrimary', 'where' => 'the big photo in the green technology area — the robotic implant arm in use', 'status' => 'live' ],
			[ 'slot' => 'techInset', 'where' => 'the small photo overlapping it — a hygienist polishing a patient’s teeth', 'status' => 'live' ],
		],
		'sections' => [
			[ 'id' => 'tour', 'where' => 'the intro above the photo tour grid', 'status' => 'live' ],
			[ 'id' => 'technology', 'where' => 'the heading and paragraph in the green technology area (the small label and the four pills are part of the design)', 'status' => 'live' ],
			[ 'id' => 'expect', 'where' => 'the heading above the four what-to-expect tiles (the tiles themselves are part of the design)', 'status' => 'live' ],
			[ 'id' => 'visit', 'where' => 'the address-hours-and-map area', 'status' => 'moved:How to find us.' ],
			[ 'id' => 'faq', 'where' => 'the heading above the questions-and-answers list', 'status' => 'moved:A few quick answers.' ],
		],
	],

	80 => [ // Contact
		'route'    => '/contact/',
		'kind'     => 'blocks',
		'liveTabs' => [ 'Hero (the words beside the message form at the top)', 'Section copy (only the “reach” row)', 'Page sections (the map area and the questions list)' ],
		'faq'      => 'inert',
		'images'   => [],
		'sections' => [
			[ 'id' => 'reach', 'where' => 'the intro above the four ways-to-reach-us cards (the cards themselves are part of the design)', 'status' => 'live' ],
			[ 'id' => 'visit', 'where' => 'the address-hours-and-map area', 'status' => 'moved:Right here in Parker.' ],
			[ 'id' => 'faq', 'where' => 'the intro above the questions-and-answers list', 'status' => 'moved:Before you reach out.' ],
		],
	],

	92 => [ // Referral Program
		'route'    => '/referral-program/',
		'kind'     => 'blocks',
		'liveTabs' => [ 'Hero', 'Images (the top photo)', 'Section copy (only the “program” row)', 'Page sections', 'Bottom of page (the booking-strip sentence only)' ],
		'faq'      => 'inert',
		'images'   => [
			[ 'slot' => 'heroPrimary', 'where' => 'the big photo to the right of the headline at the top — a patient checking in at the front desk', 'status' => 'live' ],
		],
		'sections' => [
			[ 'id' => 'program', 'where' => 'the heading and first paragraph beside the $50 + $50 reward card (the card itself is part of the design)', 'status' => 'live' ],
			[ 'id' => 'how-it-works', 'where' => 'the intro above the three numbered steps', 'status' => 'moved:Three easy steps, no paperwork.' ],
			[ 'id' => 'faq', 'where' => 'the heading above the questions-and-answers list', 'status' => 'moved:Quick answers before you refer.' ],
		],
	],

	// ── Pages with no hero group and no Page sections: photos and/or Section
	//    copy are still live on every one of them, which is why they are here.
	//    The three landing pages are paid-campaign creative — see their note.

	81 => [ // Cosmetic Dentistry LP
		'route'    => '/cosmetic-dentistry-lp/',
		'kind'     => 'template',
		'liveTabs' => [ 'Images' ],
		'orientationNote' => 'This is a paid-campaign landing page. It is hidden from Google and from the site menus on purpose, and an ad campaign points at it — so its WORDS are fixed in the page design and are not edited from these tabs. The photos below are yours to swap.',
		'images'   => [
			[ 'slot' => 'imgHeroTilted',   'where' => 'the tilted three-quarter smile photo in the group at the very top (on a phone this is the ONLY hero photo shown)', 'status' => 'live' ],
			[ 'slot' => 'imgHeroDaylight', 'where' => 'the daylight smile photo in the group at the very top', 'status' => 'live' ],
			[ 'slot' => 'imgBrushFront',   'where' => 'the veneer-being-hand-finished photo in the group at the very top', 'status' => 'live' ],
			[ 'slot' => 'imgAnnMarie',     'where' => "Ann Marie Muscarello's before/after photo in the patient results area", 'status' => 'live' ],
			[ 'slot' => 'imgBrittany',     'where' => "Brittany Fanning's before/after photo in the patient results area", 'status' => 'live' ],
			[ 'slot' => 'storyMore',       'where' => 'the photo on the first patient story card', 'status' => 'live' ],
			[ 'slot' => 'storyConfidence', 'where' => 'the photo on the second patient story card', 'status' => 'live' ],
			[ 'slot' => 'storyLength',     'where' => 'the photo on the third patient story card', 'status' => 'live' ],
			[ 'slot' => 'logoAACD',   'where' => 'the American Academy of Cosmetic Dentistry logo in the scrolling credentials strip', 'status' => 'live' ],
			[ 'slot' => 'logoAAID',   'where' => 'the American Academy of Implant Dentistry logo in the credentials strip', 'status' => 'live' ],
			[ 'slot' => 'logoFAM',    'where' => 'the Full Arch Masters logo in the credentials strip', 'status' => 'live' ],
			[ 'slot' => 'logoYomi',   'where' => 'the Yomi Robotics logo in the credentials strip', 'status' => 'live' ],
			[ 'slot' => 'logoADA',    'where' => 'the American Dental Association logo in the credentials strip', 'status' => 'live' ],
			[ 'slot' => 'logoGoogle', 'where' => 'the Google Reviews logo in the credentials strip', 'status' => 'live' ],
			[ 'slot' => 'imgRichardson',   'where' => 'the portrait of Dr. Richardson in the meet-the-dentist area', 'status' => 'live' ],
			[ 'slot' => 'imgBrush',        'where' => 'the photo in the closing section at the bottom of the page', 'status' => 'live' ],
		],
	],

	86 => [ // General LP
		'route'    => '/general-lp/',
		'kind'     => 'template',
		'liveTabs' => [ 'Images' ],
		'orientationNote' => 'This is a paid-campaign landing page. It is hidden from Google and from the site menus on purpose, and an ad campaign points at it — so its WORDS are fixed in the page design and are not edited from these tabs. The photos below are yours to swap.',
		'images'   => [
			[ 'slot' => 'imgConsult',    'where' => 'the photo beside the headline at the very top — a smiling patient', 'status' => 'live' ],
			[ 'slot' => 'imgLounge',     'where' => 'the waiting-lounge photo further down the page', 'status' => 'live' ],
			[ 'slot' => 'imgHygienist',  'where' => 'the photo of a hygienist doing a cleaning', 'status' => 'live' ],
			[ 'slot' => 'logoAACD',   'where' => 'the American Academy of Cosmetic Dentistry logo in the scrolling credentials strip', 'status' => 'live' ],
			[ 'slot' => 'logoAAID',   'where' => 'the American Academy of Implant Dentistry logo in the credentials strip', 'status' => 'live' ],
			[ 'slot' => 'logoFAM',    'where' => 'the Full Arch Masters logo in the credentials strip', 'status' => 'live' ],
			[ 'slot' => 'logoYomi',   'where' => 'the Yomi Robotics logo in the credentials strip', 'status' => 'live' ],
			[ 'slot' => 'logoADA',    'where' => 'the American Dental Association logo in the credentials strip', 'status' => 'live' ],
			[ 'slot' => 'logoGoogle', 'where' => 'the Google Reviews logo in the credentials strip', 'status' => 'live' ],
			[ 'slot' => 'imgRichardson', 'where' => 'the portrait of Dr. Richardson in the meet-the-dentist area', 'status' => 'live' ],
			[ 'slot' => 'imgDrPortrait', 'where' => 'the photo in the closing section at the bottom of the page', 'status' => 'live' ],
		],
	],

	97 => [ // Veneers LP
		'route'    => '/veneers-lp/',
		'kind'     => 'template',
		'liveTabs' => [ 'Images' ],
		'orientationNote' => 'This is a paid-campaign landing page. It is hidden from Google and from the site menus on purpose, and an ad campaign points at it — so its WORDS are fixed in the page design and are not edited from these tabs. The photos below are yours to swap.',
		'images'   => [
			[ 'slot' => 'imgHeroSide',   'where' => 'the side-view smile photo in the group at the very top', 'status' => 'live' ],
			[ 'slot' => 'imgHeroBerry',  'where' => 'the tilted three-quarter smile photo in the group at the very top', 'status' => 'live' ],
			[ 'slot' => 'imgHeroMacro',  'where' => 'the straight-on close-up smile photo in the group at the very top', 'status' => 'live' ],
			[ 'slot' => 'imgAnnMarie',   'where' => "Ann Marie Muscarello's before/after photo in the patient results area", 'status' => 'live' ],
			[ 'slot' => 'imgBrittany',   'where' => "Brittany Fanning's before/after photo in the patient results area", 'status' => 'live' ],
			[ 'slot' => 'storyMore',       'where' => 'the photo on the first patient story card', 'status' => 'live' ],
			[ 'slot' => 'storyConfidence', 'where' => 'the photo on the second patient story card', 'status' => 'live' ],
			[ 'slot' => 'storyLength',     'where' => 'the photo on the third patient story card', 'status' => 'live' ],
			[ 'slot' => 'logoAACD',   'where' => 'the American Academy of Cosmetic Dentistry logo in the scrolling credentials strip', 'status' => 'live' ],
			[ 'slot' => 'logoAAID',   'where' => 'the American Academy of Implant Dentistry logo in the credentials strip', 'status' => 'live' ],
			[ 'slot' => 'logoFAM',    'where' => 'the Full Arch Masters logo in the credentials strip', 'status' => 'live' ],
			[ 'slot' => 'logoYomi',   'where' => 'the Yomi Robotics logo in the credentials strip', 'status' => 'live' ],
			[ 'slot' => 'logoADA',    'where' => 'the American Dental Association logo in the credentials strip', 'status' => 'live' ],
			[ 'slot' => 'logoGoogle', 'where' => 'the Google Reviews logo in the credentials strip', 'status' => 'live' ],
			[ 'slot' => 'imgRichardson', 'where' => 'the portrait of Dr. Richardson in the meet-the-dentist area', 'status' => 'live' ],
			[ 'slot' => 'imgEditorial',  'where' => 'the photo in the closing section at the bottom of the page', 'status' => 'live' ],
		],
	],

	91 => [ // Privacy Policy
		'route'    => '/privacy-policy/',
		'kind'     => 'template',
		'liveTabs' => [ 'Section copy' ],
		'orientationNote' => 'The whole policy is written in the Section copy rows below — each row is one numbered part of the document, in the order it appears. There is no hero and no photo on this page.',
		'sectionsNote' => 'THIS IS A LEGAL DOCUMENT. It says what the practice does with patient information, so please do not reword it casually — have whoever handles your HIPAA compliance approve any change first. Fixing a phone number or an address here is fine.',
		'sections' => [],
	],

	95 => [ // Terms & Conditions
		'route'    => '/terms-conditions/',
		'kind'     => 'template',
		'liveTabs' => [ 'Section copy' ],
		'orientationNote' => 'The whole document is written in the Section copy rows below — each row is one numbered part, in the order it appears. There is no hero and no photo on this page.',
		'sectionsNote' => 'THIS IS A LEGAL DOCUMENT. Please do not reword it casually — have it approved before changing anything beyond a phone number or an address.',
		'sections' => [],
	],

	96 => [ // Thank you
		'route'    => '/thank-you/',
		'kind'     => 'template',
		'liveTabs' => [ 'Images' ],
		'orientationNote' => 'The page someone lands on after sending a form. Its words are part of the page design and are not edited here; the background photo is.',
		'images'   => [
			[ 'slot' => 'heroBg', 'where' => 'the full-width background photo behind the thank-you message — the team walking together outside the practice', 'status' => 'live' ],
		],
	],

	344 => [ // 404
		'route'    => '/404-2/',
		'kind'     => 'template',
		'liveTabs' => [ 'Images' ],
		'orientationNote' => 'The page someone sees at a web address that does not exist. Its words are part of the page design and are not edited here; the background photo is.',
		'images'   => [
			[ 'slot' => 'heroBg', 'where' => 'the full-width background photo — the neon smiley sign at the practice entrance', 'status' => 'live' ],
		],
	],

	346 => [ // Blog index
		'route'    => '/blog/',
		'kind'     => 'template',
		'liveTabs' => [],
		'orientationNote' => 'This page is just the list of your blog posts. It builds itself from Posts in the left-hand menu, newest first. To change what appears here, write a new post or edit an existing one — it shows up on this list at the next site build.',
	],

];

/**
 * The map entry for the page being edited, or null for a page the maps do not
 * know — which is the signal to leave every message exactly as it is.
 *
 * get_the_ID() is enough on the edit screen (the global post is set up before
 * any meta box renders); the query-string fallback covers the odd admin
 * request that renders fields before the loop.
 */
function current_page(): ?array {
	$id = get_the_ID();

	if ( ! is_int( $id ) || $id <= 0 ) {
		// Read-only routing on an admin id; nothing here is saved or echoed.
		$id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	return PAGES[ $id ] ?? null;
}

/**
 * Whether a tab is in this page's live list. Entries may carry a qualifier in
 * parentheses, so match the bare label or the label followed by a space.
 */
function tab_is_live( array $page, string $label ): bool {
	foreach ( $page['liveTabs'] as $tab ) {
		if ( $tab === $label || 0 === strpos( $tab, $label . ' ' ) ) {
			return true;
		}
	}

	return false;
}

/**
 * The live tabs as a sentence fragment: "Hero, Images and Bottom of page".
 */
function tabs_sentence( array $page ): string {
	$tabs = array_map( 'esc_html', $page['liveTabs'] ?? [] );

	// A guard, not a live path: orientation_message() now sends a page with no
	// live tabs down its own branch and never reaches this. It stays because
	// without it array_pop() on an empty list returns null and the sentence
	// reads "edited from these tabs: ." — which is how the empty case was
	// found in the first place.
	if ( ! $tabs ) {
		return 'none';
	}

	$last = array_pop( $tabs );

	return $tabs ? implode( ', ', $tabs ) . ' and ' . $last : (string) $last;
}

/**
 * Split a status into its state and, for a moved one, the target section's
 * heading — trimmed of its trailing period so it reads cleanly inside quotes.
 */
function parse_status( string $status ): array {
	if ( 0 === strpos( $status, 'moved:' ) ) {
		return [ 'moved', rtrim( trim( substr( $status, 6 ) ), '.' ) ];
	}

	return [ $status, '' ];
}

/**
 * Whether any row in a guide list is not plainly live. When every row is live
 * the guide skips the per-row "Live" tag and says so once at the bottom.
 */
function list_is_mixed( array $rows ): bool {
	foreach ( $rows as $row ) {
		if ( 'live' !== $row['status'] ) {
			return true;
		}
	}

	return false;
}

/**
 * One guide line: the code, what the visitor sees there, and the status. Only
 * inline tags, because the images guide travels through instruction text,
 * which is filtered harder than a message body.
 */
function guide_line( array $row, string $code_key, bool $mixed, bool $is_photo ): string {
	$where = rtrim( trim( $row['where'] ), '.' );

	list( $state, $target ) = parse_status( $row['status'] );

	$line = '<strong>' . esc_html( $row[ $code_key ] ) . '</strong> — ' . esc_html( $where ) . '.';

	if ( 'moved' === $state ) {
		$verb  = $is_photo ? 'changed' : 'edited';
		$line .= ' <em>Now ' . $verb . ' in Page sections — open the section “' . esc_html( $target ) . '”.</em>';
	} elseif ( 'dead' === $state ) {
		$line .= ' <em>Part of the design now — this row saves, but changes nothing; ask us if it should say something different.</em>';
	} elseif ( $mixed ) {
		$line .= $is_photo ? ' <em>Live — swap it here.</em>' : ' <em>Live — edit it here.</em>';
	}

	return $line;
}

/**
 * A link that opens this page on the real website, in a new tab.
 *
 * The point of the whole screen is that it mirrors the front end, and the
 * cheapest way to make a mirror obvious is to put the thing it mirrors one
 * click away — so an editor can hold the page and its boxes side by side
 * instead of guessing which box is which.
 *
 * The host comes from VS_FRONTEND_URL, the one place the CMS is told where the
 * site lives, so this follows the domain cutover without an edit here. If the
 * constant is unset the link is simply not drawn: a guess at the host would be
 * a second place for it to go stale, which is the rule the phone number and
 * the booking link are already held to.
 */
function view_on_site_link( array $page ): string {
	if ( ! defined( 'VS_FRONTEND_URL' ) || ! VS_FRONTEND_URL ) {
		return '';
	}

	$route = $page['route'] ?? '';

	if ( '' === $route ) {
		return '';
	}

	$url = rtrim( (string) VS_FRONTEND_URL, '/' ) . $route;

	return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">'
		. 'Open this page on the website ↗</a> — the boxes below are in the same '
		. 'order as the page itself, top to bottom.';
}

/**
 * Rewrite 1: the orientation message above the tabs, per page.
 */
function orientation_message( array $page ): string {
	$lines = [];

	if ( 'home' === $page['kind'] ) {
		$lines[] = '<strong>Almost all of this page is yours to edit.</strong>';
		$lines[] = 'The headline area at the very top is managed by us for now — the note on the <em>Hero</em> tab explains. '
			. 'Everything below it is edited from two tabs: the words in <em>Section copy</em>, the photos in <em>Images</em>. '
			. 'Each of those tabs starts with a guide saying exactly which box changes which part of the page.';
		$lines[] = 'The other tabs save, but do not change this page. Changes go live on the next site build.';
	} elseif ( 'template' === $page['kind'] && empty( $page['liveTabs'] ) ) {
		// A page with no live tabs at all — today only the blog index, which
		// builds itself from Posts. The branch below would open with "edited
		// from these tabs: none", a sentence an editor has to decode, and then
		// answer two questions this page does not raise: what "the whole list"
		// refers to, and why Page sections must stay empty. It gets a short
		// branch of its own instead, and its note carries the rest.
		$lines[] = '<strong>There is nothing to edit on this screen.</strong>';

		if ( ! empty( $page['orientationNote'] ) ) {
			$lines[] = esc_html( $page['orientationNote'] );
		}

		$lines[] = 'The tabs below do still save when you press Update — they simply do not reach the site.';
	} elseif ( 'template' === $page['kind'] ) {
		$lines[] = '<strong>This page is edited from these tabs: ' . tabs_sentence( $page ) . '.</strong>';
		$lines[] = 'That is the whole list — a box on any other tab saves, but does not change this page. '
			. '<em>Page sections</em> is empty here on purpose: this page still runs on its designed layout, '
			. 'so please leave it empty — we move pages over ourselves.';

		// A page's own note, when it has one — the three campaign landing pages
		// say their words are ad creative, the two legal pages say the rows ARE
		// the document, the blog index says it builds itself. This used to be
		// read only on the `blocks` branch, so every note on a template page
		// was written and never shown: the project's most-repeated defect,
		// caught here by rendering the message rather than reading the code.
		if ( ! empty( $page['orientationNote'] ) ) {
			$lines[] = esc_html( $page['orientationNote'] );
		}

		// Promise only the guides this page actually has. Naming the Images
		// guide on a page with no photos, or the Section copy guide on a page
		// with no rows, sends an editor to look for something that is not there.
		$guides = [];

		if ( ! empty( $page['images'] ) ) {
			$guides[] = 'The <em>Images</em> tab starts with a guide saying where each photo appears';
		}

		if ( ! empty( $page['sections'] ) || ! empty( $page['sectionsNote'] ) ) {
			$guides[] = ( $guides ? 'and <em>Section copy</em> with one saying which words sit where' : 'The <em>Section copy</em> tab starts with a note about these rows' );
		}

		$lines[] = ( $guides ? implode( ', ', $guides ) . '. ' : '' ) . 'Changes go live on the next site build.';
	} else {
		$lines[] = '<strong>The words visitors read on this page are edited in <em>Page sections</em> — '
			. 'one row per part of the page, top to bottom.</strong>';

		if ( ! empty( $page['images'] ) ) {
			$lines[] = 'For photos, open the <em>Images</em> tab and read the guide at the top first — it says '
				. 'where each photo appears, and which photos are changed inside <em>Page sections</em> these days.';
		}

		$lines[] = 'The full list of tabs that change this page: ' . tabs_sentence( $page ) . '. '
			. 'A box on any other tab saves, but does not change the page.';

		if ( ! empty( $page['orientationNote'] ) ) {
			$lines[] = esc_html( $page['orientationNote'] );
		}

		$lines[] = 'Changes go live on the next site build.';
	}

	$link = view_on_site_link( $page );

	if ( '' !== $link ) {
		$lines[] = $link;
	}

	return implode( "\n", $lines );
}

/**
 * Rewrite 2: the Hero tab message on a page whose hero the site does not read.
 */
function hero_message(): string {
	return '<strong>On this page the top area is managed by us.</strong>' . "\n"
		. 'The big headline, the small line above it, the sentence under it and the buttons are hand-built '
		. 'into this page’s design — the boxes below save, but do not change the page. If the top of the page '
		. 'should say something different, tell us and we will change it, or connect these boxes so they work '
		. 'here the way they do on the other pages.' . "\n"
		. 'Everything below the headline area is yours — the note at the very top of this screen says which tabs to use.';
}

/**
 * Rewrite 3: the guide at the top of the Images tab.
 */
function images_guide( array $page ): string {
	$mixed = list_is_mixed( $page['images'] );

	$lines   = [];
	$lines[] = 'Where each photo appears on the page — match the code in the <em>Photo code</em> column.';

	foreach ( $page['images'] as $row ) {
		$lines[] = guide_line( $row, 'slot', $mixed, true );
	}

	if ( ! empty( $page['imagesNote'] ) ) {
		$lines[] = esc_html( $page['imagesNote'] );
	}

	$footer = 'To swap a photo, change the Image and leave the code alone — it ties the photo to its place.';

	if ( $mixed ) {
		$footer .= ' Keep every row, even one whose photo is changed in Page sections these days: the site '
			. 'still checks the row exists, and deleting one can stop the next build.';
	} else {
		$footer .= ' Keep every row — deleting one can stop the next build.';
	}

	$footer .= ' Alt text describes the picture for screen readers and search engines; it is worth writing '
		. 'properly on every photo.';

	$lines[] = $footer;

	return implode( '<br>', $lines );
}

/**
 * Rewrite 4: the guide at the top of the Section copy tab.
 */
function sections_guide( array $page ): string {
	$mixed = list_is_mixed( $page['sections'] );

	$lines = [];

	// A page can carry a note and NO per-row list — the two legal pages do,
	// where the rows are a document rather than page furniture and naming 34 or
	// 45 of them individually would help nobody. Emitting the "where each row
	// appears" header above an empty list, and "every row above is live" below
	// it, is what this guard prevents.
	if ( empty( $page['sections'] ) ) {
		if ( ! empty( $page['sectionsNote'] ) ) {
			return esc_html( $page['sectionsNote'] );
		}

		return '';
	}

	$lines[] = '<strong>Where each row below appears on the page — match its Section code box.</strong>';

	foreach ( $page['sections'] as $row ) {
		$lines[] = guide_line( $row, 'id', $mixed, false );
	}

	if ( $mixed ) {
		$lines[] = 'Edit the words in the rows marked live, and leave each Section code exactly as it is. '
			. 'A row marked “now edited in Page sections” still saves here, but the site reads the matching '
			. 'section there instead.';
	} else {
		$lines[] = 'Every row above is live: edit the words, and leave each Section code exactly as it is.';
	}

	// The same escape hatch the images guide has.
	if ( ! empty( $page['sectionsNote'] ) ) {
		$lines[] = esc_html( $page['sectionsNote'] );
	}

	return implode( "\n", $lines );
}

/**
 * Rewrite 5: the FAQ tab, on pages whose visible questions moved into Page
 * sections. Two flavours, because the pages differ in one honest detail: on
 * most of them the old list still feeds what Google shows for the page, so an
 * edit in Page sections should be mirrored here; on the rest the old list is
 * simply done.
 */
function faq_instructions( string $mode ): string {
	$note = 'The questions visitors see on this page are edited in <em>Page sections</em> now — open the '
		. 'questions-and-answers section there.';

	if ( 'mirror' === $mode ) {
		return $note . ' The list below still feeds what Google shows for this page in its search results, '
			. 'so after changing a question there, make the same change here to keep the two matching.';
	}

	return $note . ' The list below saves, but the site no longer reads it.';
}

/**
 * The one entry point. Runs at priority 20, after vs-content-model.php’s own
 * prepare filters: its hide filter may hand us `false` for a retired field,
 * which passes straight through, and the one field both files rewrite — the
 * photo code box — is guarded on opposite conditions, so the two can never
 * disagree about the same row.
 */
function rewrite_guidance( $field ) {
	if ( ! is_array( $field ) ) {
		return $field;
	}

	$page = current_page();

	if ( null === $page ) {
		return $field;
	}

	switch ( $field['key'] ?? '' ) {
		case 'field_vs_page_intro':
			$field['message'] = orientation_message( $page );
			break;

		case 'field_vs_hero_intro':
			if ( ! tab_is_live( $page, 'Hero' ) ) {
				$field['message'] = hero_message();
			}
			break;

		case 'field_vs_images':
			if ( ! empty( $page['images'] ) ) {
				$field['instructions'] = images_guide( $page );
			}
			break;

		case 'field_vs_image_slot':
			// Only a row that already holds a code, and only on a page whose
			// guide exists to point at. A blank row keeps the unlock message
			// from vs-content-model.php, which is the right one there: it
			// explains that a code is created on save.
			if ( ! empty( $page['images'] ) && '' !== trim( (string) ( $field['value'] ?? '' ) ) ) {
				$field['instructions'] = 'Filled in automatically — this code ties the photo to its place, and '
					. 'the guide above says where each one appears. To change a photo, change the Image, '
					. 'not this code.';
			}
			break;

		case 'field_vs_sections_note':
			if ( ! empty( $page['sections'] ) || ! empty( $page['sectionsNote'] ) ) {
				$field['message'] = sections_guide( $page );
			}
			break;

		case 'field_vs_faqs':
			if ( ! empty( $page['faq'] ) ) {
				$field['instructions'] = faq_instructions( $page['faq'] );
			}
			break;
	}

	return $field;
}
add_filter( 'acf/prepare_field', __NAMESPACE__ . '\\rewrite_guidance', 20 );

/**
 * ─── The Page sections row handles ──────────────────────────────────────────
 *
 * A collapsed row in Page sections says only what KIND of section it is:
 * "Photo and copy", "Card grid", "Process steps". On a page with fourteen rows
 * that is fourteen handles naming a shape, and none of them naming a place. An
 * editor told "the bit about sedation is wrong" has to open rows one at a time
 * until they find it, and the two "Photo and copy" rows look identical until
 * they are open. The names are also the wrong vocabulary to search with: the
 * client thinks in headings, because a heading is what they read on the page.
 *
 * So each handle gains its own section's heading:
 *
 *     Photo and copy: Sedation Options for Anxious Patients
 *
 * ADMIN CHROME ONLY. This filter runs while ACF draws the edit screen. It
 * stores nothing, changes no field value, and is invisible to WPGraphQL and
 * therefore to the Astro build — the failure mode of getting it wrong is an
 * ugly row handle, not a wrong page.
 *
 * PLAIN TEXT, NO MARKUP. ACF passes the finished title through its own escaper
 * before printing it, and which tags survive that has changed between ACF
 * versions. A heading may legitimately contain `<em>`, so it is stripped here
 * rather than gambled on.
 */

/** How much of a heading fits in a row handle before it starts wrapping. */
const HANDLE_CHARS = 56;

/**
 * Trim to HANDLE_CHARS on a word boundary, with an ellipsis when it was cut.
 * Falls back to a hard cut for a single word longer than the budget.
 */
function shorten_handle( string $text ): string {
	$text = trim( preg_replace( '/\s+/u', ' ', $text ) );

	if ( '' === $text || mb_strlen( $text ) <= HANDLE_CHARS ) {
		return $text;
	}

	$cut   = mb_substr( $text, 0, HANDLE_CHARS );
	$space = mb_strrpos( $cut, ' ' );

	if ( false !== $space && $space > 20 ) {
		$cut = mb_substr( $cut, 0, $space );
	}

	return rtrim( $cut, " ,.;:—-" ) . '…';
}

/**
 * The human label a `band_key` value stands for, read off the layout's own
 * select rather than the constant in vs-content-model.php — the two files are
 * deployed separately, and a lookup that spans them can go stale in the window
 * where only one has been uploaded.
 *
 * The labels end in the page they belong to, as in "… (Emergency Dentistry)".
 * That is useful in the dropdown, where every section on the site is listed
 * together, and noise in a handle on the very page it names.
 */
function code_band_label( array $layout, string $value ): string {
	foreach ( ( $layout['sub_fields'] ?? [] ) as $sub ) {
		if ( 'band_key' !== ( $sub['name'] ?? '' ) ) {
			continue;
		}

		$label = (string) ( $sub['choices'][ $value ] ?? '' );

		return trim( preg_replace( '/\s*\([^()]*\)\s*$/u', '', $label ) );
	}

	return '';
}

/**
 * What this row should say it is, or '' to leave the handle alone.
 *
 * Order matters. A built-in section has no heading of its own — its wording
 * lives in the design — so its picker is the only thing that identifies it, and
 * is checked first. Otherwise the heading is what the visitor reads and what
 * the editor will think of the section by. `nav_label` is the last resort: a
 * section can legitimately have no heading (a band that opens with a photo),
 * and its side-menu name is then the only words attached to it.
 */
function row_summary( array $layout ): string {
	$band_key = get_sub_field( 'band_key' );

	if ( is_string( $band_key ) && '' !== $band_key ) {
		$label = code_band_label( $layout, $band_key );

		if ( '' !== $label ) {
			return shorten_handle( $label );
		}
	}

	foreach ( [ 'heading', 'nav_label' ] as $name ) {
		$value = get_sub_field( $name );

		if ( ! is_string( $value ) ) {
			continue;
		}

		// wp_strip_all_tags() rather than a regex: the heading field documents
		// `<em>` and an editor may well type something else in there too.
		$value = shorten_handle( wp_strip_all_tags( $value ) );

		if ( '' !== $value ) {
			return $value;
		}
	}

	return '';
}

/**
 * Append the summary to the layout's name. ACF sets up the row's loop context
 * before firing this, which is what makes get_sub_field() work here.
 *
 * A row still being added has no values yet, so `row_summary()` returns '' and
 * the handle keeps the bare layout name until the editor types a heading.
 */
function layout_title( $title, $field, $layout, $i ) {
	if ( ! is_array( $layout ) ) {
		return $title;
	}

	$summary = row_summary( $layout );

	if ( '' === $summary ) {
		return $title;
	}

	// A colon, not a dash: several of the built-in sections' labels contain an
	// em dash of their own ("Service area — Parker map, address and
	// directions"), and a dash joiner turns those into two dashes competing to
	// be the separator.
	return $title . ': ' . $summary;
}
add_filter( 'acf/fields/flexible_content/layout_title/name=blocks', __NAMESPACE__ . '\\layout_title', 10, 4 );

/**
 * ─── Grouping a section's boxes so they mirror the section ──────────────────
 *
 * A section row is one flat list of boxes: thirty of them for "Photo and copy",
 * twenty-three for "Comparison cards". Nothing in that list says which box is
 * the heading you can see, which is the panel underneath it, and which three
 * you should never touch. Allan's words, and they are the right test: "the
 * fields of each page will be like a mirror on the front end."
 *
 * So the boxes are re-grouped under headings that walk down the section the way
 * a visitor's eye does — the heading at the top, then the cards, then the panel
 * under them, then the buttons — with every non-content control collected into
 * one closing group the editor can ignore.
 *
 * WHY THIS LIVES HERE AND RUNS ON `acf/prepare_field`.
 *
 * The obvious place to reorder sub-fields is where they are declared, in
 * vs-content-model.php. That file is the GraphQL contract: 37 block types and
 * 325 fields that all 48 routes are built from, and this project's most
 * expensive failures have been schema-shaped. `acf/prepare_field` runs only
 * when wp-admin DRAWS a field. It is not part of registering the schema and not
 * part of resolving a query, so nothing done here can reach WPGraphQL — the
 * separation is structural rather than careful.
 *
 * That was checked rather than assumed. Against the live endpoint, `PageFields`
 * exposes exactly nine fields — blocks, cards, closing, faqs, hero, images,
 * processSteps, sections, tocLinks — and NONE of the model's five `tab` fields
 * or its `message` fields appear anywhere in the schema. WPGraphQL for ACF
 * skips field types that carry no value, which is the class an `accordion`
 * belongs to. A fingerprint of all 37 block types and their 325 fields was
 * taken before this filter existed, and is re-taken after: identical, or this
 * comes out.
 *
 * NOTHING CAN VANISH. The plan lists field NAMES, not keys — the names are
 * identical across layouts by design, which is what lets one entry describe the
 * preamble everywhere. Any field the plan does not mention falls through to the
 * closing settings group rather than being dropped, so a field added to a
 * layout later appears in wp-admin whether or not anyone updates this table.
 * Ordering is presentation only: ACF stores values by key, so a reordered row
 * loads and saves exactly as before.
 */

/**
 * layout name → the groups, in the order the section renders.
 *
 * Each group is [ heading, [ field names, in order ] ]. The closing settings
 * group is added automatically and holds everything not named here.
 *
 * HOW THE ORDER WAS ESTABLISHED. One agent per layout read the layout's PHP and
 * its Astro component's template together and reported the order things are
 * actually drawn in, citing line numbers; a second, adversarial agent per
 * layout re-derived that order from the component and tried to fault it. Eleven
 * of thirteen came back with corrections, which is the point of running the
 * second pass — the most useful was MediaSplit, where the obvious reading puts
 * the section's `body` at the top with the heading, and the component draws it
 * at MediaSplitBlock.astro:807, inside `.prose`, AFTER the quote and the
 * checklist. It is grouped with the words beside the photo for that reason.
 *
 * THE FIELD LISTS ARE THE SCHEMA'S, NOT A GREP'S. The first attempt built them
 * by regex over this repo and silently missed every field `block_preamble()`
 * contributes, because those keys are concatenated (`$k . 'anchor'`) rather
 * than written out — so `eyebrow`, `heading` and `body` were absent from twelve
 * of thirteen plans and would have fallen through into the settings group, one
 * step short of putting a page's own heading under "you can usually leave these
 * alone". The lists were re-taken from the live GraphQL schema, which enumerates
 * exactly the fields each layout has: 244 across sixteen layouts.
 *
 * CHECKED MECHANICALLY, per layout: no field named twice, no field named that
 * the layout does not have, and nothing left to fall through except things that
 * really are settings — background, menu name, jump-to name, column counts,
 * heading width, stacking width, photo side, panel position.
 *
 * `code_section` is deliberately absent. It has three fields; grouping them
 * would add furniture, not remove it.
 */
const SECTION_GROUPS = [
	'faq' => [
		[ 'The heading at the top', [ 'eyebrow', 'heading', 'body' ] ],
		[ 'The questions', [ 'items' ] ],
		[ 'The note beside the questions', [ 'pull' ] ],
		[ 'The buttons at the end', [ 'cta_label', 'cta_href', 'cta_hover', 'cta_phone_first' ] ],
	],
	'card_grid' => [
		[ 'The heading at the top', [ 'eyebrow', 'heading', 'body' ] ],
		[ 'The cards across the middle', [ 'cards_eyebrow', 'cards' ] ],
		[ 'The panel under the cards', [ 'callout_eyebrow', 'callout_heading', 'callout_body', 'callout_points' ] ],
		[ 'The buttons at the end', [ 'cta_label', 'cta_href', 'cta_hover', 'cta_label_2', 'cta_href_2', 'cta_hover_2', 'cta_note' ] ],
	],
	'media_split' => [
		[ 'The heading at the top', [ 'eyebrow', 'heading' ] ],
		[ 'The photo', [ 'image', 'image_alt' ] ],
		[ 'The words beside the photo', [ 'quote', 'quote_attrib', 'checklist', 'body', 'body_2_heading', 'body_2', 'body_3_heading', 'body_3' ] ],
		[ 'The row of big numbers under the words', [ 'creds' ] ],
		[ 'The boxed note beside the words', [ 'callout_eyebrow', 'callout_heading', 'callout_body' ] ],
		[ 'The buttons under the words', [ 'cta_label', 'cta_href', 'cta_hover', 'cta_label_2', 'cta_href_2', 'cta_hover_2' ] ],
		[ 'The cards and closing line at the bottom', [ 'sub_cards', 'sub_foot', 'cta_text' ] ],
	],
	'process_steps' => [
		[ 'The heading at the top', [ 'eyebrow', 'heading', 'body' ] ],
		[ 'The cards above the steps', [ 'pre_cards' ] ],
		[ 'The numbered steps', [ 'steps' ] ],
		[ 'The paragraph under the steps', [ 'sub_foot' ] ],
		[ 'The line that closes the section', [ 'cta_text' ] ],
	],
	'comparison_cards' => [
		[ 'The heading at the top', [ 'eyebrow', 'heading', 'body' ] ],
		[ 'The cards across the middle', [ 'tiers' ] ],
		[ 'The panel under the cards', [ 'callout_eyebrow', 'callout_heading', 'callout_body' ] ],
		[ 'The smaller cards below the panel', [ 'alt_cards' ] ],
		[ 'The list of terms at the bottom', [ 'glossary_eyebrow', 'glossary_heading', 'glossary_body', 'glossary' ] ],
		[ 'The buttons at the end', [ 'cta_label', 'cta_href', 'cta_hover', 'cta_label_2', 'cta_href_2', 'cta_hover_2', 'cta_note', 'cta_hide' ] ],
	],
	'pricing_tiers' => [
		[ 'The heading at the top', [ 'eyebrow', 'heading', 'body' ] ],
		[ 'The price cards across the middle', [ 'plans' ] ],
		[ 'The line under the cards', [ 'note' ] ],
		[ 'The buttons at the end', [ 'cta_label', 'cta_href', 'cta_hover', 'cta_label_2', 'cta_href_2', 'cta_hover_2', 'cta_note' ] ],
	],
	'stat_callout' => [
		[ 'The big number on the left', [ 'value', 'unit', 'caption' ] ],
		[ 'The words beside the number', [ 'eyebrow', 'heading', 'intro', 'body_heading', 'body', 'intro_2' ] ],
		[ 'The list of points', [ 'points' ] ],
	],
	'copy_plus_stats' => [
		[ 'The heading at the top', [ 'eyebrow', 'heading', 'body' ] ],
		[ 'More words below that', [ 'body_2_heading', 'body_2', 'body_3_heading', 'body_3' ] ],
		[ 'The number cards beside the words', [ 'stats' ] ],
		[ 'The button at the foot of the words', [ 'cta_label', 'cta_href', 'cta_hover' ] ],
	],
	'tech_grid' => [
		[ 'The heading at the top', [ 'eyebrow', 'heading', 'body' ] ],
		[ 'The numbered cards', [ 'tech_cards' ] ],
		[ 'The words in the panel below', [ 'callout_eyebrow', 'callout_heading', 'callout_body' ] ],
		[ 'The photo in that panel', [ 'image', 'image_alt', 'image_tag' ] ],
	],
	'service_cards' => [
		[ 'The heading at the top', [ 'eyebrow', 'heading', 'body' ] ],
		[ 'The tiles across the middle', [ 'svc_cards' ] ],
		[ 'The paragraph under the tiles', [ 'sub_foot' ] ],
		[ 'The buttons at the end', [ 'cta_label', 'cta_href', 'cta_hover', 'cta_label_2', 'cta_href_2', 'cta_hover_2', 'cta_note' ] ],
	],
	'doctor_profiles' => [
		[ 'The heading at the top', [ 'eyebrow', 'heading', 'body' ] ],
		[ 'The photo down the left side', [ 'image', 'image_alt', 'pill' ] ],
		[ 'The write-ups beside the photo', [ 'bios' ] ],
	],
	'candidacy_ledger' => [
		[ 'The heading at the top', [ 'eyebrow', 'heading', 'body' ] ],
		[ 'The writing down the left side', [ 'copy_heading', 'copy_body', 'copy_heading_2', 'copy_body_2' ] ],
		[ 'The buttons under that writing', [ 'cta_label', 'cta_href', 'cta_hover', 'cta_label_2', 'cta_href_2', 'cta_hover_2' ] ],
		[ 'The checklist box on the right', [ 'ledger_eyebrow', 'ledger_heading', 'ledger' ] ],
	],
	'gallery_marquee' => [
		[ 'The heading at the top', [ 'eyebrow', 'heading', 'body', 'body_2' ] ],
		[ 'The quote under the photos', [ 'quote', 'quote_attrib' ] ],
		[ 'The buttons at the end', [ 'cta_label', 'cta_href', 'cta_hover', 'cta_label_2', 'cta_href_2', 'cta_hover_2', 'cta_note' ] ],
	],
	'callout_list' => [
		[ 'The heading at the top', [ 'eyebrow', 'heading', 'body' ] ],
		[ 'The list', [ 'lines' ] ],
	],
	'map_visit' => [
		[ 'The heading at the top', [ 'eyebrow', 'heading', 'body' ] ],
	],
];

/** The heading every layout's leftover controls are collected under. */
const SETTINGS_GROUP = 'Settings — you can usually leave these alone';

/**
 * One accordion separator. `open` on the first group only, so a row opens
 * showing the words and nothing else; `multi_expand` so opening one does not
 * shut another. `endpoint` is what makes the PREVIOUS accordion stop here —
 * without it ACF nests every following field under the first one.
 */
function group_divider( string $layout, string $title, int $i, bool $open ): array {
	return [
		'key'          => 'field_vs_grp_' . $layout . '_' . $i,
		'label'        => $title,
		'name'         => '',
		'type'         => 'accordion',
		'open'         => $open ? 1 : 0,
		'multi_expand' => 1,
		'endpoint'     => 0,
	];
}

/**
 * Re-order one layout's sub-fields into its groups and splice the accordions in.
 * Returns the sub-fields unchanged when the layout has no plan.
 */
function group_layout_fields( string $layout, array $subs ): array {
	$plan = SECTION_GROUPS[ $layout ] ?? null;

	if ( null === $plan ) {
		return $subs;
	}

	// Index by name. A layout with two fields of one name would collide, which
	// the model does not do — but take the first and leave the rest to the
	// leftovers rather than silently dropping one.
	$by_name = [];

	foreach ( $subs as $i => $sub ) {
		$name = $sub['name'] ?? '';

		if ( '' !== $name && ! isset( $by_name[ $name ] ) ) {
			$by_name[ $name ] = $i;
		}
	}

	$out   = [];
	$taken = [];
	$n     = 0;

	foreach ( $plan as $group ) {
		list( $title, $names ) = $group;

		$members = [];

		foreach ( $names as $name ) {
			if ( isset( $by_name[ $name ] ) && ! isset( $taken[ $by_name[ $name ] ] ) ) {
				$members[]                    = $subs[ $by_name[ $name ] ];
				$taken[ $by_name[ $name ] ] = true;
			}
		}

		// A group whose fields are all absent draws no empty accordion.
		if ( ! $members ) {
			continue;
		}

		$out[] = group_divider( $layout, $title, $n, 0 === $n );
		$out   = array_merge( $out, $members );
		++$n;
	}

	// Everything the plan did not name, in its original order. This is the
	// safety net: a field added to a layout later still appears.
	$rest = [];

	foreach ( $subs as $i => $sub ) {
		if ( ! isset( $taken[ $i ] ) ) {
			$rest[] = $sub;
		}
	}

	if ( $rest ) {
		$out[] = group_divider( $layout, SETTINGS_GROUP, $n, false );
		$out   = array_merge( $out, $rest );
	}

	return $out;
}

/**
 * Rewrite the Page sections field as wp-admin draws it.
 *
 * Guarded on the field key so this touches one field and nothing else, and
 * returns the field untouched the moment anything is not the shape expected —
 * a wrong guess here would be a broken edit screen on 20 live pages.
 */
function group_section_fields( $field ) {
	if ( ! is_array( $field ) || 'field_vs_blocks' !== ( $field['key'] ?? '' ) ) {
		return $field;
	}

	if ( empty( $field['layouts'] ) || ! is_array( $field['layouts'] ) ) {
		return $field;
	}

	foreach ( $field['layouts'] as $i => $layout ) {
		if ( ! is_array( $layout ) || empty( $layout['sub_fields'] ) || ! is_array( $layout['sub_fields'] ) ) {
			continue;
		}

		$field['layouts'][ $i ]['sub_fields'] = group_layout_fields(
			(string) ( $layout['name'] ?? '' ),
			$layout['sub_fields']
		);
	}

	return $field;
}
add_filter( 'acf/prepare_field', __NAMESPACE__ . '\\group_section_fields', 30 );

/**
 * ─── Repeater rows collapse, and each one says what it is ──────────────────
 *
 * Every repeater in the model renders each row fully expanded. On a section
 * with six cards that is six stacks of boxes; on /privacy-policy/ it is
 * nineteen numbered clauses open at once, and on /terms-conditions/
 * twenty-three — the two pages where an editor is most likely to be hunting
 * for one specific paragraph. Twenty-one of the twenty-four repeaters were
 * declared with no `collapsed` setting at all, so none of them could be
 * folded shut.
 *
 * Setting `collapsed` to a sub-field does two things: rows arrive closed, and
 * each closed row shows THAT sub-field's value as its title. So the legal
 * pages become a numbered contents list, and a card grid becomes a list of
 * card titles — the same idea as the section handles, one level down.
 *
 * The sub-field chosen is the one a person would use to find the row again:
 * the heading for a section, the question for an FAQ, the title for a card,
 * the plan name for a price. Applied on `acf/prepare_field` for the same
 * reason as the grouping above — it is a drawing concern, and cannot reach
 * the schema from here.
 *
 * The four `table`-layout repeaters (the photo list, the “On this page” menu,
 * the credential figures, the hero buttons) are left alone: a table row is
 * already one line, and collapsing it would hide the only thing it shows.
 */
const REPEATER_ROW_TITLE = [
	'field_vs_process_steps' => 'field_vs_step_title', // process_steps → title
	'field_vs_sections' => 'field_vs_section_heading', // sections → heading
	'field_vs_cards' => 'field_vs_card_title', // cards → title
	'field_vs_faqs' => 'field_vs_faq_q', // faqs → question
	'field_vs_blk_faq_items' => 'field_vs_blk_faq_q', // items → question
	'field_vs_blk_cards_cards' => 'field_vs_blk_cards_card_title', // cards → title
	'field_vs_blk_media_sub_cards' => 'field_vs_blk_media_sub_card_title', // sub_cards → title
	'field_vs_blk_steps_pre_cards' => 'field_vs_blk_steps_pre_card_heading', // pre_cards → heading
	'field_vs_blk_steps_steps' => 'field_vs_blk_steps_step_title', // steps → title
	'field_vs_blk_compare_cards' => 'field_vs_blk_compare_card_title', // tiers → title
	'field_vs_blk_compare_alt_cards' => 'field_vs_blk_compare_alt_card_title', // alt_cards → title
	'field_vs_blk_compare_glossary' => 'field_vs_blk_compare_glossary_row_title', // glossary → title
	'field_vs_blk_pricing_plans' => 'field_vs_blk_pricing_plan_name', // plans → name
	'field_vs_blk_stat_points' => 'field_vs_blk_stat_point_lead', // points → lead
	'field_vs_blk_cstats_stats' => 'field_vs_blk_cstats_stat_label', // stats → label
	'field_vs_blk_tech_cards' => 'field_vs_blk_tech_card_title', // tech_cards → title
	'field_vs_blk_svc_cards' => 'field_vs_blk_svc_card_title', // svc_cards → title
	'field_vs_blk_docs_bios' => 'field_vs_blk_docs_bio_heading', // bios → heading
	'field_vs_blk_cand_ledger' => 'field_vs_blk_cand_ledger_title', // ledger → title
];

/**
 * Give a repeater its row title. Returns the field untouched unless it is one
 * of the repeaters above and still has no `collapsed` of its own, so a setting
 * added in the content model later wins over this table rather than fighting
 * it.
 */
function collapse_repeater_rows( $field ) {
	if ( ! is_array( $field ) || 'repeater' !== ( $field['type'] ?? '' ) ) {
		return $field;
	}

	$sub = REPEATER_ROW_TITLE[ $field['key'] ?? '' ] ?? null;

	if ( null === $sub || ! empty( $field['collapsed'] ) ) {
		return $field;
	}

	$field['collapsed'] = $sub;

	// No stored preference means this editor has never folded this repeater,
	// so fold it for them once — see print_first_load_collapse() below.
	if ( '' === (string) acf_get_user_setting( 'collapsed_' . $field['key'], '' ) ) {
		first_load_collapse( $field['key'] );
	}

	return $field;
}
add_filter( 'acf/prepare_field', __NAMESPACE__ . '\\collapse_repeater_rows', 30 );

/**
 * ─── Rows arrive folded the first time ─────────────────────────────────────
 *
 * `collapsed` above gives every row a fold control and a title; it does not
 * fold anything. ACF decides that from a per-user preference, which a new
 * editor does not have — so their FIRST sight of /privacy-policy/ would still
 * be nineteen clauses open at once, which is the thing being fixed.
 *
 * So on a render where ACF holds no preference for one of these repeaters, a
 * few lines of script fold its rows. It only adds the class ACF's own toggle
 * adds and writes no preference of its own, so the moment the editor folds or
 * unfolds anything themselves, ACF stores that and this stops applying.
 *
 * Deliberately not done by clicking each toggle: on a nineteen-row page that
 * would fire nineteen of ACF's preference writes on page load.
 */
function first_load_collapse( ?string $key = null ): array {
	static $keys = [];

	if ( null !== $key && ! in_array( $key, $keys, true ) ) {
		$keys[] = $key;
	}

	return $keys;
}

function print_first_load_collapse(): void {
	$keys = first_load_collapse();

	if ( ! $keys ) {
		return;
	}

	?>
	<script>
	( function () {
		var keys = <?php echo wp_json_encode( $keys ); ?>;

		// querySelectorAll, not querySelector: a repeater nested in a section
		// layout is drawn once per row of that section, so there is rarely only
		// one of them on the page.
		function fold( root ) {
			keys.forEach( function ( key ) {
				( root || document )
					.querySelectorAll( '.acf-field[data-key="' + key + '"]' )
					.forEach( function ( field ) {
						field.querySelectorAll( '.acf-row:not(.acf-clone)' ).forEach( function ( row ) {
							row.classList.add( '-collapsed' );
						} );
					} );
			} );
		}

		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', function () { fold(); } );
		} else {
			fold();
		}

		// ACF does not set up the fields inside a folded section row until that
		// row is opened, and setting them up rebuilds the rows — which drops the
		// class the pass above added. So fold again when ACF says a repeater is
		// ready, and once more after a section handle is clicked open.
		if ( window.acf && typeof acf.addAction === 'function' ) {
			acf.addAction( 'ready_field/type=repeater', function ( field ) {
				if ( field && field.$el ) { fold( field.$el[0].parentNode || document ); }
			} );
			acf.addAction( 'append_field/type=repeater', function () { fold(); } );
		}

		document.addEventListener( 'click', function ( e ) {
			if ( e.target.closest && e.target.closest( '.acf-fc-layout-handle' ) ) {
				setTimeout( function () { fold(); }, 60 );
			}
		}, true );
	} )();
	</script>
	<?php
}
add_action( 'admin_footer', __NAMESPACE__ . '\\print_first_load_collapse' );
