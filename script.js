/**
 * Kanchi Farm Stay - Main Script
 * Handles data, navigation, and page logic
 */

// DATA: Rooms
const rooms = [
    {
        id: 'wooden-villa',
        name: "Wooden Villa",
        shortDescription: "Unique rooftop villa with panoramic views and private terrace.",
        fullDescription: "Perched on the top floor, the Wooden Villa offers a unique stay experience. This 'penthouse' style accommodation features rustic wooden architecture and opens onto a private terrace with sweeping views of the farm.",
        amenities: ["Private Terrace", "Panoramic View", "Rustic Interiors", "King Bed", "Outdoor Seating", "WiFi", "Pool", "Pump Set Play", "Barbecue", "Pets Allowed", "Garden", "Fish Pond", "Farm Animals (Rabbits, Dogs, Goats, Cow, Turkey, Chicken, Ducks & more for Kids)", "Availability"],
        image: "assets/images/wooden-villa-swing-3.jpg",
        images: [
            "assets/images/wooden-villa-swing-3.jpg",
            "assets/images/wooden-villa-swing-4.jpg",
            "assets/images/wooden-villa-swing-5.jpg",
            "assets/images/gallery-room-swing.jpg",
            "assets/images/wooden-villa-bathroom.png"
        ],
        price: "₹3,000 / night",
        numericPrice: 3000,
        capacity: "3 Adults, 1 Child",
        size: "512 sqft",
        airbnbUrl: "https://airbnb.co.in/h/kanchifarmstay-woodenvilla",
        bookingUrl: "https://www.booking.com/Share-m1A8St"
    },
    {
        id: 'white-villa',
        name: "White Villa — Room 1",
        shortDescription: "A luxurious white-themed villa offering elegance and serenity.",
        fullDescription: "White Villa Room 1 is located on the first floor of the villa. It stands as a beacon of elegance amidst the green farm. With its pristine white architecture and spacious interiors, it offers a luxurious stay for those who appreciate style and comfort.",
        amenities: ["King Size Bed", "Private Entrance", "Modern Interiors", "Air Conditioning", "Large Windows", "Attached Bathroom", "TV in Hall", "Outdoor Seating", "WiFi", "Pool", "Pump Set Play", "Barbecue", "Pets Allowed", "Garden", "Fish Pond", "Farm Animals (Rabbits, Dogs, Goats, Cow, Turkey, Chicken, Ducks & more for Kids)", "Availability"],
        image: "assets/images/white-villa-main-hero.jpg",
        images: [
            "assets/images/white-villa-main-hero.jpg",
            "assets/images/white-villa-1.jpg",
            "assets/images/white-villa-2.jpg",
            "assets/images/white-villa-3.jpg",
            "assets/images/white-villa-4.jpg"
        ],
        price: "₹2,000 / night",
        numericPrice: 2000,
        capacity: "2 Adults, 1 Child",
        size: "300 sqft",
        airbnbUrl: "https://airbnb.co.in/h/kanchifarmstay-whitevilla",
        bookingUrl: "https://www.booking.com/Share-HedGP2U"
    },
    {
        id: 'white-villa-room-2',
        name: "White Villa — Room 2",
        shortDescription: "A cozy room in the White Villa, perfect for a comfortable and affordable stay.",
        fullDescription: "White Villa Room 2 is located on the first floor of the villa, with the bathroom conveniently situated on the ground floor. It offers the same elegant white architecture and serene surroundings as Room 1, in a cozy and comfortable setting. Ideal for an affordable yet stylish farm stay.",
        amenities: ["Double Bed", "Private Entrance", "Modern Interiors", "Air Conditioning", "Large Windows", "Bathroom on Ground Floor", "TV in Hall", "Outdoor Seating", "WiFi", "Pool", "Pump Set Play", "Barbecue", "Pets Allowed", "Garden", "Fish Pond", "Farm Animals (Rabbits, Dogs, Goats, Cow, Turkey, Chicken, Ducks & more for Kids)", "Availability"],
        image: "assets/images/white-villa-main-hero.jpg",
        images: [
            "assets/images/white-villa-3.jpg",
            "assets/images/white-villa-4.jpg",
            "assets/images/white-villa-1.jpg",
            "assets/images/white-villa-2.jpg",
            "assets/images/white-villa-main-hero.jpg"
        ],
        price: "₹1,000 / night",
        numericPrice: 1000,
        capacity: "2 Adults, 1 Child",
        size: "200 sqft",
        airbnbUrl: "https://airbnb.co.in/h/kanchifarmstay-whitevilla",
        bookingUrl: "contact.html"
    },
    {
        id: 'white-villa-full-floor',
        name: "White Villa — Full 1st Floor",
        shortDescription: "Exclusive booking of the entire first floor with 2 rooms, hall, balcony and terrace access.",
        fullDescription: "Experience the entire first floor of the White Villa — includes both Room 1 and Room 2, a shared hall with TV, balcony, and terrace access. Room 1 features an attached bathroom while a second common bathroom serves the floor. Perfect for families or groups looking for a private, spacious stay with modern comforts. Enjoy access to the outdoor pool, lush garden, dining, and Wi-Fi.",
        amenities: ["2 Rooms", "1 Attached Bathroom", "1 Common Bathroom", "TV in Hall", "Balcony", "Terrace Access", "Outdoor Pool", "Dining", "Air Conditioning", "Private Entrance", "Outdoor Seating", "WiFi", "Pool", "Pump Set Play", "Barbecue", "Pets Allowed", "Garden", "Fish Pond", "Farm Animals (Rabbits, Dogs, Goats, Cow, Turkey, Chicken, Ducks & more for Kids)", "Availability"],
        image: "assets/images/white-villa-main-hero.jpg",
        images: [
            "assets/images/white-villa-main-hero.jpg",
            "assets/images/white-villa-1.jpg",
            "assets/images/white-villa-2.jpg",
            "assets/images/white-villa-3.jpg",
            "assets/images/white-villa-4.jpg"
        ],
        price: "₹2,500 / night",
        numericPrice: 2500,
        capacity: "4 Adults, 2 Children",
        size: "Full 1st Floor (500 sqft)",
        airbnbUrl: "https://airbnb.co.in/h/kanchifarmstay-whitevilla",
        bookingUrl: "contact.html"
    },
    {
        id: 'natures-nest',
        name: "Nature's Nest",
        shortDescription: "A cozy retreat offering a blend of rustic charm and modern comfort.",
        fullDescription: "Nature's Nest is perfect for couples or small families. Featuring a comfortable queen-sized bed and views of the surrounding greenery, it offers a peaceful respite from the bustle of city life.",
        amenities: ["Queen Size Bed", "Garden View", "Air Conditioning", "Attached Bathroom", "Outdoor Seating", "WiFi", "Pool", "Pump Set Play", "Barbecue", "Pets Allowed", "Garden", "Fish Pond", "Farm Animals (Rabbits, Dogs, Goats, Cow, Turkey, Chicken, Ducks & more for Kids)", "Availability"],
        image: "assets/images/natures-nest-main.jpg",
        images: [
            "assets/images/natures-nest-main.jpg",
            "assets/images/natures-nest-1.jpg",
            "assets/images/natures-nest-2.jpg",
            "assets/images/natures-nest-3.jpg"
        ],
        price: "₹2,500 / night",
        numericPrice: 2500,
        capacity: "4 Adults, 2 Children",
        size: "300 sqft",
        airbnbUrl: "https://airbnb.co.in/h/kanchifarmstay-naturesnest",
        bookingUrl: "https://www.booking.com/Share-ckOtkG"
    },
    {
        id: 'tranquil-retreat',
        name: "Tranquil Retreat",
        shortDescription: "Spacious suite in the main farmhouse with access to verandas.",
        fullDescription: "The Tranquil Retreat suite offers an expansive layout within the main farmhouse structure. It features premium furnishings, a king-sized bed, and direct access to the breezy verandas.",
        amenities: ["King Size Bed", "Spacious Living Area", "Work Desk", "Air Conditioning", "Veranda Access", "Outdoor Seating", "WiFi", "Pool", "Pump Set Play", "Barbecue", "Pets Allowed", "Garden", "Fish Pond", "Farm Animals (Rabbits, Dogs, Goats, Cow, Turkey, Chicken, Ducks & more for Kids)", "Availability"],
        image: "assets/images/tranquil-retreat-main.jpg",
        images: [
            "assets/images/tranquil-retreat-main.jpg",
            "assets/images/tranquil-retreat-1.jpg",
            "assets/images/tranquil-retreat-2.jpg",
            "assets/images/tranquil-retreat-3.jpg",
            "assets/images/tranquil-retreat-4.jpg"
        ],
        price: "₹2,500 / night",
        numericPrice: 2500,
        capacity: "4 Adults, 2 Children",
        size: "450 sqft",
        airbnbUrl: "https://airbnb.co.in/h/kanchifarmstay-tranquilretreat",
        bookingUrl: "https://www.booking.com/Share-ywNmBh"
    },
    {
        id: 'wooden-cottage',
        name: "Wooden Cottage",
        shortDescription: "Get away from it all when you stay under the stars.",
        fullDescription: "A group of friends created this place as an escape from buzzing city life. A perfect peaceful getaway without gadgets with friends and family. Features a private pool and farm stay setting.",
        amenities: ["King Bed", "Kitchen", "Private Pool", "Air Conditioning", "Outdoor Seating", "WiFi", "Pool", "Pump Set Play", "Barbecue", "Pets Allowed", "Garden", "Fish Pond", "Farm Animals (Rabbits, Dogs, Goats, Cow, Turkey, Chicken, Ducks & more for Kids)", "Availability"],
        image: "assets/images/wooden-cottage-1.jpg",
        images: [
            "assets/images/wooden-cottage-1.jpg",
            "assets/images/wooden-cottage-2.jpg",
            "assets/images/wooden-cottage-3.jpg",
            "assets/images/wooden-cottage-4.jpg",
            "assets/images/wooden-cottage-5.jpg",
            "assets/images/wooden-cottage-6.jpg",
            "assets/images/wooden-cottage-7.jpg",
            "assets/images/wooden-cottage-8.jpg"
        ],
        price: "₹3,000 / night",
        numericPrice: 3000,
        capacity: "3 Adults, 1 Child",
        size: "Contact for details",
        airbnbUrl: "https://airbnb.co.in/h/kanchifarmstay-woodencottage",
        bookingUrl: "index.html#contact"
    },
    {
        id: 'kanchi-farm-stay',
        name: "KanchiFarmStay (Group Booking)",
        shortDescription: "Exclusive booking of the entire farm stay for large groups and private events.",
        fullDescription: "Experience the ultimate private getaway by booking the entire Kanchi Farm Stay. Perfect for large family gatherings, corporate retreats, or special events. Enjoy exclusive access to all our villas, cottages, common areas, dining hall, and sprawling gardens without any other guests.",
        amenities: ["Exclusive Access", "All Bedrooms", "Dining Hall", "Full Kitchen", "Private Grounds", "Event Space", "Campfire", "Outdoor Seating", "WiFi", "Pool", "Pump Set Play", "Barbecue", "Pets Allowed", "Garden", "Fish Pond", "Farm Animals (Rabbits, Dogs, Goats, Cow, Turkey, Chicken, Ducks & more for Kids)", "Availability"],
        image: "assets/images/farm-hero.jpg",
        images: [
            "assets/images/farm-hero.jpg",
            "assets/images/farm-aerial.jpg",
            "assets/images/farm-new-1753.jpg",
            "assets/images/farm-new-1754.jpg",
            "assets/images/farm-new-1755.jpg",
            "assets/images/farm-new-1756.jpg",
            "assets/images/farm-new-1757.jpg",
            "assets/images/farm-new-1759.jpg",
            "assets/images/farm-new-1752.jpg",
            "assets/images/gallery-dining-hall-1.jpg",
            "assets/images/gallery-tent-camping.jpg",
            "assets/images/farm-exterior.jpg"
        ],
        price: "₹8,000 / night (up to 10 guests)",
        numericPrice: 8000,
        capacity: "Large Groups (10+ Guests)",
        size: "Entire 5-Acre Property",
        airbnbUrl: "contact.html",
        bookingUrl: "contact.html"
    },
    {
        id: 'tent',
        name: "Tent",
        shortDescription: "Sleep under the stars in our comfortable farm stay tent.",
        fullDescription: "Sleep under the stars and wake up to the sound of nature in our spacious tent. It provides all the rustic charm of camping without sacrificing comfort. Perfect for adventurous couples or friends looking for a unique farm stay experience close to nature.",
        amenities: ["Comfortable Beds", "Shared Bathroom Access", "Campfire Access", "Star Gazing", "Fan", "Outdoor Seating", "WiFi", "Pool", "Pump Set Play", "Barbecue", "Pets Allowed", "Garden", "Fish Pond", "Farm Animals (Rabbits, Dogs, Goats, Cow, Turkey, Chicken, Ducks & more for Kids)", "Availability"],
        image: "assets/images/tent-accommodation-3.jpg",
        images: [
            "assets/images/tent-new-1.jpg",
            "assets/images/tent-new-2.jpg",
            "assets/images/tent-new-3.jpg",
            "assets/images/tent-new-4.jpg",
            "assets/images/tent-new-5.jpg",
            "assets/images/tent-new-6.jpg"
        ],
        price: "₹500 / night",
        numericPrice: 500,
        capacity: "2 Guests",
        size: "Cozy Tent Setup",
        airbnbUrl: "contact.html",
        bookingUrl: "contact.html"
    },
    {
        id: 'tree-house',
        name: "Tree House",
        shortDescription: "A unique treetop experience — climb up and sleep among the branches.",
        fullDescription: "Our Tree House is a one-of-a-kind stay built among the trees of the farm. Accessible by a bamboo ladder, it offers a thrilling yet peaceful retreat for adventurers who want to feel truly connected to nature. Wake up to birdsong and a canopy view unlike anything else.",
        amenities: ["Unique Treetop Setting", "Bamboo Ladder Access", "Star Gazing", "Campfire Access", "Farm Views", "Nature Immersion", "Outdoor Seating", "WiFi", "Pool", "Pump Set Play", "Barbecue", "Pets Allowed", "Garden", "Fish Pond", "Farm Animals (Rabbits, Dogs, Goats, Cow, Turkey, Chicken, Ducks & more for Kids)", "Availability"],
        image: "assets/images/treehouse-1.jpg",
        images: [
            "assets/images/treehouse-1.jpg",
            "assets/images/treehouse-2.jpg"
        ],
        price: "Contact Us",
        numericPrice: 0,
        capacity: "2 Guests",
        size: "Treetop Cabin",
        airbnbUrl: "contact.html",
        bookingUrl: "contact.html"
    }
];

// DATA: Reviews
const reviews = [
    {
        id: 1,
        name: "Khushi Lakhanpal",
        rating: 5,
        text: "We stayed here for two nights while in Kanchipuram for work, and it exceeded our expectations in every way! The room was spotless, spacious, and very comfortable and excellent value for money. The food was fresh, homely, and exactly what we needed after long workdays. What truly makes this place special is the family who runs it. Their hospitality was warm, attentive, and genuine. The farm is beautifully maintained, and being surrounded by animals — especially the incredibly friendly dogs — added so much charm to the experience. We genuinely felt like we were staying with our own extended family. Highly recommend to anyone visiting Kanchipuram!",
        date: "March 2026",
        platform: "google",
        room: "Kanchi Farm Stay"
    },
    {
        id: 2,
        name: "Adwaith Shine",
        rating: 5,
        text: "Amazing experience staying here, a cute farm stay. Revathy is an amazing care taker. She makes delicious food. I came from Kerala and I stayed here for 3 days. And I always felt like home.",
        date: "March 2026",
        platform: "google",
        room: "Kanchi Farm Stay"
    },
    {
        id: 3,
        name: "mathuja rajalingam",
        rating: 5,
        text: "We had a wonderful stay there. Fantastic place. The hospitality was excellent and we felt very welcomed. A special thanks to the sister there who took care of us so kindly. She made our stay even more comfortable and memorable. All are very friendly and kind hearted.",
        date: "March 2026",
        platform: "google",
        room: "Kanchi Farm Stay"
    },
    {
        id: 4,
        name: "Dipesh Shaw",
        rating: 5,
        text: "Recently, I went to Kanchi Farm Stay with my family, and we had a very nice experience there. Their hospitality, politeness, and the way they welcomed us were excellent. They cooked delicious food for us, and my entire family truly had a wonderful time there. The way the farm has been developed gives you a very serene feeling. Clean water, fresh air, a fish pond, birds, rabbits, hens, and turkeys — you really feel close to nature. I felt healed from within. The locality and the surrounding fields were amazing. I suggest people book this place for a weekend getaway, and I am sure they will really enjoy it.",
        date: "January 2026",
        platform: "google",
        room: "Kanchi Farm Stay"
    },
    {
        id: 5,
        name: "Neha Riya",
        rating: 5,
        text: "A hidden gem near Kanchipuram. Loved the natural surroundings, peaceful atmosphere, and delicious homemade food. The stay was very comfortable and the hospitality was excellent. Ideal for families, couples, or anyone who wants a calm break in nature. Would definitely visit again!",
        date: "January 2026",
        platform: "google",
        room: "Kanchi Farm Stay"
    },
    {
        id: 6,
        name: "Sri Kanth Madarapu",
        rating: 5,
        text: "This is a very great place to enjoy yourself near Kanchipuram. The food was excellent — never think of a second option. The cottage people are very helpful and the surroundings are beautiful. If you come in a car, this stay is a very best option for a peaceful getaway.",
        date: "February 2026",
        platform: "google",
        room: "Kanchi Farm Stay"
    },
    {
        id: 7,
        name: "Naga Karri",
        rating: 5,
        text: "Great hospitality, home cooked food, peaceful location far away from town. Recommended for anyone who wants to stay a few kilometres away from the city and reconnect with nature.",
        date: "March 2026",
        platform: "google",
        room: "Kanchi Farm Stay"
    },
    {
        id: 8,
        name: "Lohith TM",
        rating: 5,
        text: "Our farm stay experience was truly amazing! The wooden villa was excellent, with brilliant ventilation that made the entire stay extremely comfortable. From the moment we arrived, we felt so much positivity in the surroundings. Highly recommended for a refreshing getaway.",
        date: "January 2026",
        platform: "google",
        room: "Wooden Villa"
    },
    {
        id: 9,
        name: "Jayanthi Gurureddy",
        rating: 5,
        text: "Amazing home stay with super food. Highly recommend for family to spend quality time here.",
        date: "January 2026",
        platform: "google",
        room: "Kanchi Farm Stay"
    },
    {
        id: 10,
        name: "Ganesh Devaraj",
        rating: 5,
        text: "Very good and silent place. One can stay there for peace of mind with nature.",
        date: "January 2026",
        platform: "google",
        room: "Kanchi Farm Stay"
    },
    {
        id: 11,
        name: "Rino",
        rating: 5,
        text: "One of the best stays you can find around Kanchipuram. A wonderful host, delicious and homely food, and a beautiful, well-maintained property. The place is very peaceful and calm, making it perfect for a relaxing getaway.",
        date: "28 December 2025",
        platform: "booking",
        room: "Wooden Villa"
    },
    {
        id: 12,
        name: "Angela",
        rating: 5,
        text: "This is a beautiful place, surrounded by trees and countryside. We were well looked after in this beautiful place. I was sorry to leave.",
        date: "11 January 2026",
        platform: "booking",
        room: "Wooden Villa"
    },
    {
        id: 13,
        name: "Jayakumar",
        rating: 5,
        text: "Peaceful stay, felt like spending time in our home. Mrs. Revathi (relative to host) prepared food for our complete stay — homely and tasty. One can choose this place to spend their time, come and enjoy the surroundings full of trees.",
        date: "April 2026",
        platform: "airbnb",
        room: "Wooden Villa"
    },
    {
        id: 14,
        name: "Raji",
        rating: 5,
        text: "We travelled from Madurai to Kanchipuram for getting a wedding saree. Even though it was midnight, they responded and arranged everything. Stay is good and people travelling for temple and saree purchase can have a good peaceful stay here.",
        date: "April 2026",
        platform: "airbnb",
        room: "Wooden Cottage"
    },
    {
        id: 15,
        name: "Vignesh",
        rating: 5,
        text: "The best Airbnb experience I have ever had. The only thing is that it's slightly on the outskirts — and I think that's exactly how an Airbnb experience should be. It's a beautiful farm, and both the villa and the cottage are spacious, with well-equipped furniture. The place is very aesthetically pleasing. The customer service is top-notch, and the quality of the food cannot be expressed in words. The food is South Indian and absolutely delicious. Every Airbnb usually has some small flaws here and there, but this one was perfect to the last bit.",
        date: "February 2026",
        platform: "airbnb",
        room: "Wooden Cottage"
    },
    {
        id: 16,
        name: "Saurabh",
        rating: 5,
        text: "Must visit place with lot of greenery. It is a village setting and hence very quiet and peaceful. We visited in paddy season and all around there were paddy fields, adding to the greenery. Geeta ji and her parents are super friendly and go out of their way to make your stay comfortable. Must-do activities: (1) Have fresh coconut water and flesh — it tastes different from what we get in cities. (2) Visit the main campus and play with their pets and feed them coconut flesh. The location, warmth, and hospitality of the hosts made it a wonderful experience and we would definitely plan a longer stay on our next Chennai visit.",
        date: "January 2026",
        platform: "airbnb",
        room: "Wooden Villa"
    },
    {
        id: 17,
        name: "Jagadesh",
        rating: 5,
        text: "Exceptional Stay! This place exceeded my expectations in every way! The house was spotlessly clean, beautifully maintained, and had every amenity I needed. The location was stunning, with breathtaking views and a peaceful atmosphere that made for a truly relaxing stay. Our host was outstanding, providing clear communication and ensuring that everything was perfect for our arrival. I highly recommend this property to anyone looking for a comfortable, scenic, and serene getaway. We can't wait to return!",
        date: "November 2024",
        platform: "airbnb",
        room: "Nature's Nest"
    },
    {
        id: 18,
        name: "Shankar",
        rating: 5,
        text: "This turned out to be one of those rare properties where the images matched the actual! The place is approx 40 mins distance from Kancheepuram and in a rural location. They have rabbits, turkeys, dogs etc in their farm and are very friendly. The caretaker Mrs. Revathi is very friendly and made us feel right at home. Her cooking is super tasty and worth every penny that you pay. The owner's father is also a very friendly chap and always ready to help with anything. Had interesting conversations with him.",
        date: "December 2025",
        platform: "airbnb",
        room: "Wooden Villa"
    },
    {
        id: 19,
        name: "Sanjay",
        rating: 5,
        text: "Excellent room, service and location. Very clean and airy room. Best of rural and urban. I liked everything!",
        date: "April 2026",
        platform: "booking",
        room: "Kanchi Farm Stay"
    },
    {
        id: 20,
        name: "Lakhanpal",
        rating: 5,
        text: "Incredibly lovely experience, will be recommending to every single person I know! We stayed here for two nights while in Kanchipuram for work, and the experience exceeded all expectations. The room was excellent — clean, spacious, and extremely comfortable, truly good value for money. The food was absolutely delicious and prepared with great care. What made this stay exceptional was the warmth of the family managing the property. Even though the three of us are not fluent in Tamil, they were incredibly patient and accommodating. Every request, every question, and every small need was attended to immediately and graciously. The farm itself is beautiful and very well maintained. Being surrounded by animals — bunnies, chicks, turkeys, ducks, fishes, and especially the friendliest dogs — added so much charm to the experience. We genuinely felt like we were staying with our own extended family. Everything was smooth, peaceful, and thoughtfully handled.",
        date: "February 2026",
        platform: "booking",
        room: "Kanchi Farm Stay"
    },
    {
        id: 21,
        name: "Emilia",
        rating: 5,
        text: "Very nice place, house with garden and animals! A lovely hostess with two daughters. Pleasant, rural atmosphere.",
        date: "January 2026",
        platform: "booking",
        room: "Kanchi Farm Stay"
    },
    {
        id: 22,
        name: "Pascal",
        rating: 5,
        text: "Very friendly welcome and very good meals. They even organised a day trip by auto rickshaw to visit Kanchipuram. A little way from the city, but that is part of the charm of this peaceful rural retreat.",
        date: "March 2026",
        platform: "booking",
        room: "Kanchi Farm Stay"
    }
];
// COMPONENTS: Navbar
function renderNavbar() {
    const navbarHTML = `
    <nav class="navbar">
        <div class="container navbar-container">
            <a href="index.html" class="navbar-logo">
                <img src="assets/images/logo.png" alt="Kanchi Farm Stay" class="logo-img" onerror="this.innerText='Kanchi Farm Stay'">
                <span style="display:none">Kanchi Farm Stay</span>
            </a>
            <div class="navbar-toggle" id="navbar-toggle" onclick="toggleMenu()">
                <span class="bar"></span>
                <span class="bar"></span>
                <span class="bar"></span>
            </div>
            <div class="navbar-links" id="navbar-links">
                <a href="index.html" class="nav-link ${isActive('index.html')}">Home</a>
                <a href="gallery.html" class="nav-link ${isActive('gallery.html')}">Gallery</a>
                <a href="accommodations.html" class="nav-link ${isActive('accommodations.html')}">Accommodations</a>
                <a href="reviews.html" class="nav-link ${isActive('reviews.html')}">Customer Reviews</a>
                <a href="attractions.html" class="nav-link ${isActive('attractions.html')}">Attractions</a>
                <a href="food-menu.html" class="nav-link ${isActive('food-menu.html')}">Food Menu</a>
                <a href="contact.html" class="nav-link ${isActive('contact.html')}">Contact Us</a>
                <a href="accommodations.html" class="booking-btn btn" style="color:white;text-decoration:none;">Book Now</a>
            </div>
        </div>
    </nav>
    `;
    const headerElement = document.getElementById('navbar-placeholder');
    if (headerElement) headerElement.innerHTML = navbarHTML;
}

// COMPONENTS: Footer
function renderFooter() {
    const footerHTML = `
    <footer class="footer">
        <div class="container footer-container">
            <div class="footer-col">
                <h3>Kanchi Farm Stay</h3>
                <p>Experience the serenity of rural life with modern comforts. A perfect getaway for tranquility and nature lovers.</p>
                <div class="footer-social">
                    <a href="https://www.instagram.com/kanchifarmstay" target="_blank" rel="noopener noreferrer" class="social-link social-instagram" aria-label="Instagram">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>
                        @kanchifarmstay
                    </a>
                    <a href="https://www.facebook.com/kanchifarmstay" target="_blank" rel="noopener noreferrer" class="social-link social-facebook" aria-label="Facebook">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                        kanchifarmstay
                    </a>
                </div>
            </div>
            <div class="footer-col">
                <h4>Quick Links</h4>
                <ul>
                    <li><a href="index.html">Home</a></li>
                    <li><a href="accommodations.html">Accommodations</a></li>
                    <li><a href="index.html#activities">Activities</a></li>
                    <li><a href="gallery.html">Gallery</a></li>
                    <li><a href="reviews.html">Customer Reviews</a></li>
                    <li><a href="attractions.html">Attractions</a></li>
                    <li><a href="food-menu.html">Food Menu</a></li>
                    <li><a href="contact.html">Contact Us</a></li>
                </ul>
            </div>
            <div class="footer-col">
                <h4>Contact</h4>
                <p>📍 704, Sastha Nagar, Kovil Street, Chithathur, Tiruvannamalai, Tamil Nadu</p>
                <p>📞 <a href="tel:+916383726094">+91 6383726094</a></p>
                <p>📞 <a href="tel:+918825775747">+91 8825775747</a></p>
                <p>📧 <a href="mailto:ops@kanchifarmstay.com">ops@kanchifarmstay.com</a></p>
                <p>
                    <a href="https://airbnb.co.in/h/kanchifarmstay" target="_blank" rel="noopener noreferrer" style="color:rgba(255,255,255,0.8);">Also on Airbnb</a>
                    &nbsp;·&nbsp;
                    <a href="https://www.booking.com/hotel/in/kanchi-farm-stay.en-gb.html" target="_blank" rel="noopener noreferrer" style="color:rgba(255,255,255,0.8);">Booking.com</a>
                </p>
            </div>
        </div>
        <div class="footer-bottom">
            <p>&copy; ${new Date().getFullYear()} Kanchi Farm Stay. All rights reserved.</p>
        </div>
    </footer>
    `;
    const footerElement = document.getElementById('footer-placeholder');
    if (footerElement) footerElement.innerHTML = footerHTML;
}

// LOGIC: Mobile Menu
function toggleMenu() {
    const links = document.getElementById('navbar-links');
    links.classList.toggle('active');
}

// LOGIC: Active Link Helper
function isActive(page) {
    const path = window.location.pathname;
    const pageName = path.split('/').pop() || 'index.html';
    // Match with or without .html extension
    const pageBase = page.replace(/\.html$/, '');
    const nameBase = pageName.replace(/\.html$/, '') || 'index';
    return (nameBase === pageBase || pageName === page) ? 'active' : '';
}

// LOGIC: Room Details Page
function loadRoomDetails() {
    const params = new URLSearchParams(window.location.search);
    const roomId = params.get('id');
    const room = rooms.find(r => r.id === roomId);

    if (room) {
        // Hero Image
        const heroImg = document.getElementById('room-hero-img');
        if (heroImg) heroImg.src = room.image;

        // Title
        const title = document.getElementById('room-title');
        if (title) title.innerText = room.name;

        // Full Description
        const desc = document.getElementById('room-full-desc');
        if (desc) desc.innerText = room.fullDescription;

        // Price
        const price = document.getElementById('room-price');
        if (price) price.innerText = room.price;

        // Capacity
        const cap = document.getElementById('room-capacity');
        if (cap) cap.innerText = room.capacity;

        // Size
        const size = document.getElementById('room-size');
        if (size) size.innerText = room.size;

        // Amenities
        const amenitiesList = document.getElementById('room-amenities');
        if (amenitiesList) {
            amenitiesList.innerHTML = room.amenities.map(item => `<li>${item}</li>`).join('');
        }

        // Gallery
        const galleryGrid = document.getElementById('room-gallery-grid');
        if (galleryGrid) {
            galleryGrid.innerHTML = room.images.map(img => `
                <div class="gallery-item">
                    <img src="${img}" alt="${room.name}">
                </div>
            `).join('');
        }

        // Booking Links
        const airbnbBtn = document.getElementById('airbnb-btn');
        if (airbnbBtn) airbnbBtn.href = room.airbnbUrl;

        const bookingBtn = document.getElementById('booking-btn');
        if (bookingBtn) bookingBtn.href = room.bookingUrl;

        // ── Availability: fetch blocked ranges, then wire up pickers ──
        // blockedRanges: array of [checkIn, checkOut] strings
        let blockedRanges = [];

        // Build a Set of all individual blocked date strings YYYY-MM-DD
        function buildBlockedSet(ranges) {
            const set = new Set();
            ranges.forEach(([ci, co]) => {
                let cur = new Date(ci + 'T00:00:00');
                const end = new Date(co + 'T00:00:00');
                while (cur < end) {
                    set.add(cur.toISOString().split('T')[0]);
                    cur.setDate(cur.getDate() + 1);
                }
            });
            return set;
        }

        // Returns true if [checkIn, checkOut) overlaps any blocked range
        function isRangeBlocked(checkIn, checkOut, ranges) {
            for (const [ci, co] of ranges) {
                // overlap: ci < checkOut AND co > checkIn
                if (ci < checkOut && co > checkIn) return true;
            }
            return false;
        }

        // Find the next available check-in date on or after `fromDate`
        function nextAvailableDate(fromDateStr, blockedSet) {
            let d = new Date(fromDateStr + 'T00:00:00');
            for (let i = 0; i < 365; i++) {
                const s = d.toISOString().split('T')[0];
                if (!blockedSet.has(s)) return s;
                d.setDate(d.getDate() + 1);
            }
            return fromDateStr;
        }

        const checkinInput  = document.getElementById('room-checkin');
        const checkoutInput = document.getElementById('room-checkout');
        const totalPriceEl  = document.getElementById('room-total-price');
        const razorpayBtn   = document.getElementById('razorpay-btn');
        const availMsg      = document.getElementById('availability-message');

        const todayStr    = new Date().toISOString().split('T')[0];
        const tomorrowStr = (() => { const d = new Date(); d.setDate(d.getDate()+1); return d.toISOString().split('T')[0]; })();

        let days = 1;
        let currentTotal = room.numericPrice;

        function setBookingError(msg) {
            if (availMsg) {
                availMsg.textContent = msg;
                availMsg.style.display = msg ? 'block' : 'none';
            }
            if (razorpayBtn) {
                razorpayBtn.disabled = true;
                razorpayBtn.style.opacity = '0.5';
                razorpayBtn.style.cursor = 'not-allowed';
            }
        }

        function clearBookingError() {
            if (availMsg) availMsg.style.display = 'none';
            if (razorpayBtn) {
                razorpayBtn.disabled = false;
                razorpayBtn.style.opacity = '1';
                razorpayBtn.style.cursor = 'pointer';
            }
        }

        function updateTotalPrice() {
            const ci = checkinInput?.value;
            const co = checkoutInput?.value;
            if (!ci || !co || co <= ci) {
                if (totalPriceEl) totalPriceEl.innerText = 'Select dates';
                setBookingError('Please select valid check-in and check-out dates.');
                days = 0; currentTotal = 0;
                return;
            }

            // Check overlap with blocked ranges
            if (isRangeBlocked(ci, co, blockedRanges)) {
                if (totalPriceEl) totalPriceEl.innerText = 'Unavailable';
                setBookingError('These dates are not available. Please choose different dates.');
                days = 0; currentTotal = 0;
                return;
            }

            const diffTime = new Date(co) - new Date(ci);
            days = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
            currentTotal = room.numericPrice * days;
            if (totalPriceEl) totalPriceEl.innerText = `₹${currentTotal.toLocaleString()}`;
            clearBookingError();
        }

        function initPickers() {
            if (!checkinInput || !checkoutInput) return;
            const blockedSet = buildBlockedSet(blockedRanges);

            checkinInput.min  = todayStr;
            checkoutInput.min = tomorrowStr;

            // Set initial values to first available pair
            const firstAvail = nextAvailableDate(todayStr, blockedSet);
            checkinInput.value = firstAvail;
            const dayAfter = new Date(firstAvail + 'T00:00:00');
            dayAfter.setDate(dayAfter.getDate() + 1);
            checkoutInput.value = nextAvailableDate(dayAfter.toISOString().split('T')[0], blockedSet);
            updateTotalPrice();

            checkinInput.addEventListener('change', () => {
                const newMin = new Date(checkinInput.value + 'T00:00:00');
                newMin.setDate(newMin.getDate() + 1);
                const newMinStr = newMin.toISOString().split('T')[0];
                checkoutInput.min = newMinStr;
                if (!checkoutInput.value || checkoutInput.value <= checkinInput.value) {
                    checkoutInput.value = nextAvailableDate(newMinStr, blockedSet);
                }
                updateTotalPrice();
            });

            checkoutInput.addEventListener('change', updateTotalPrice);
        }

        // Fetch blocked dates from channel manager, then init
        fetch(`channel-manager/availability-api.php?room=${encodeURIComponent(room.id)}`)
            .then(r => r.json())
            .then(data => {
                blockedRanges = data.blocked || [];
                loadAvailabilityCalendar(room.id, blockedRanges);
                initPickers();
            })
            .catch(() => {
                // API unavailable — init pickers without blocking
                initPickers();
                loadAvailabilityCalendar(room.id, []);
            });

        // ── Razorpay payment ──────────────────────────────────────────
        if (razorpayBtn) {
            razorpayBtn.addEventListener('click', async (e) => {
                e.preventDefault();

                const guestName  = document.getElementById('guest-name')?.value.trim();
                const guestEmail = document.getElementById('guest-email')?.value.trim();
                const guestPhone = document.getElementById('guest-phone')?.value.trim();

                if (!guestName || !guestEmail || !guestPhone) {
                    alert('Please fill in your Full Name, Email Address, and Phone Number before proceeding to payment.');
                    return;
                }

                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(guestEmail)) {
                    alert('Please enter a valid email address (e.g. you@example.com).');
                    document.getElementById('guest-email')?.focus();
                    return;
                }

                // Accept Indian (+91) and international formats, 7–15 digits
                const phoneDigits = guestPhone.replace(/[\s\-().+]/g, '');
                if (!/^\d{7,15}$/.test(phoneDigits)) {
                    alert('Please enter a valid phone number (7–15 digits, e.g. +91 98765 43210).');
                    document.getElementById('guest-phone')?.focus();
                    return;
                }
                if (days <= 0) {
                    alert('Please select valid Check-in and Check-out dates.');
                    return;
                }

                const originalText = razorpayBtn.innerText;
                razorpayBtn.innerText = 'Checking availability…';
                razorpayBtn.disabled = true;

                try {
                    // 1. Real-time availability check (server-side)
                    const availRes  = await fetch('check-availability.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ roomId: room.id, checkIn: checkinInput.value, checkOut: checkoutInput.value })
                    });
                    const availData = await availRes.json();
                    if (!availData.available) {
                        // Refresh blocked ranges in case something was just booked
                        const fresh = await fetch(`channel-manager/availability-api.php?room=${encodeURIComponent(room.id)}`);
                        const freshData = await fresh.json();
                        blockedRanges = freshData.blocked || [];
                        loadAvailabilityCalendar(room.id, blockedRanges);
                        updateTotalPrice();
                        setBookingError(availData.message || 'These dates are no longer available.');
                        razorpayBtn.innerText = originalText;
                        return;
                    }

                    razorpayBtn.innerText = 'Processing…';

                    // 2. Create Razorpay order
                    const amountInPaise = currentTotal * 100;
                    const response = await fetch('create_order.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            amount: amountInPaise,
                            guestName, guestEmail, guestPhone,
                            checkin: checkinInput.value,
                            checkout: checkoutInput.value,
                            roomName: room.name,
                            days
                        })
                    });
                    const orderData = await response.json();
                    if (!response.ok || !orderData.id) {
                        throw new Error(orderData.error || orderData.error_description || 'Failed to create order');
                    }

                    // 3. Open Razorpay checkout
                    const options = {
                        key: 'rzp_live_SImDeaehZI93nG',
                        amount: amountInPaise,
                        currency: 'INR',
                        name: 'Kanchi Farm Stay',
                        description: `${room.name} — ${days} night${days > 1 ? 's' : ''}`,
                        image: window.location.origin + '/assets/images/logo.png',
                        order_id: orderData.id,
                        handler: function (rzpResponse) {
                            // Save to channel manager DB + WhatsApp notify
                            fetch('confirm_booking.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({
                                    paymentId:  rzpResponse.razorpay_payment_id,
                                    orderId:    rzpResponse.razorpay_order_id,
                                    roomId:     room.id,
                                    roomName:   room.name,
                                    checkIn:    checkinInput.value,
                                    checkOut:   checkoutInput.value,
                                    guestName, guestEmail, guestPhone,
                                    amount: currentTotal, days
                                })
                            }).then(() => {
                                // Refresh availability after booking confirmed
                                fetch(`channel-manager/availability-api.php?room=${encodeURIComponent(room.id)}`)
                                    .then(r => r.json())
                                    .then(d => {
                                        blockedRanges = d.blocked || [];
                                        loadAvailabilityCalendar(room.id, blockedRanges);
                                        updateTotalPrice();
                                    }).catch(() => {});
                            }).catch(() => {});

                            alert(`Payment Successful!\nPayment ID: ${rzpResponse.razorpay_payment_id}\nRoom: ${room.name}\nCheck-in: ${checkinInput.value}\nCheck-out: ${checkoutInput.value}\nGuest: ${guestName}\nThank you for booking with Kanchi Farm Stay!`);
                        },
                        prefill: { name: guestName, email: guestEmail, contact: guestPhone },
                        notes: { room_id: room.id, checkin: checkinInput.value, checkout: checkoutInput.value, days },
                        theme: { color: '#6B8E23' }
                    };

                    const rzp1 = new window.Razorpay(options);
                    rzp1.on('payment.failed', (r) => alert('Payment Failed. Reason: ' + r.error.description));
                    rzp1.on('modal.closed', () => { razorpayBtn.innerText = originalText; razorpayBtn.disabled = false; });
                    rzp1.open();

                } catch (error) {
                    console.error('Booking error:', error);
                    alert("Unable to reach payment server. " + error.message);
                    razorpayBtn.innerText = originalText;
                    razorpayBtn.disabled = false;
                }
            });
        }

        // Hero Image Slider
        if (room.images && room.images.length > 1) {
            initRoomSlider(room.images);
        }

    } else {
        // Handle not found
        document.body.innerHTML = '<div class="container section text-center"><h1>Room Not Found</h1><a href="accommodations.html" class="btn">View All Rooms</a></div>';
    }
}

// LOGIC: Reviews Page
let currentPlatformFilter = 'all';

function loadReviews() {
    const grid = document.getElementById('reviews-grid');
    if (!grid) return;

    // Filtering logic
    const filteredReviews = currentPlatformFilter === 'all'
        ? reviews
        : reviews.filter(r => r.platform === currentPlatformFilter);

    const reviewsHTML = filteredReviews.map(review => `
        <div class="review-card">
            <div class="review-header">
                <div class="reviewer-info">
                   <div class="reviewer-avatar">${review.name.charAt(0)}</div>
                   <div>
                       <h3 class="reviewer-name">${review.name}</h3>
                       <p class="review-date">${review.date}</p>
                   </div>
                </div>
                <div class="platform-badge" style="background-color: ${getPlatformColor(review.platform)}">
                    ${review.platform === 'airbnb' ? 'Airbnb' : review.platform === 'booking' ? 'Booking.com' : 'Google'}
                </div>
            </div>
            <div class="review-rating">
                <div class="stars">★★★★★</div>
                <span class="room-tag">${review.room || 'Stay'}</span>
            </div>
            <p class="review-text">"${review.text}"</p>
        </div>
    `).join('');

    if (filteredReviews.length === 0) {
        grid.innerHTML = '<p class="no-reviews">No reviews found for this filter.</p>';
    } else {
        grid.innerHTML = reviewsHTML;
    }
}

function filterReviews(platform) {
    currentPlatformFilter = platform;
    loadReviews();

    const buttons = document.querySelectorAll('.filter-btn');
    buttons.forEach(btn => {
        // Remove active class
        btn.classList.remove('active');
        // Add active class if onclick matches
        if (btn.getAttribute('onclick').includes(`'${platform}'`)) {
            btn.classList.add('active');
        }
    });
}

function getPlatformColor(platform) {
    if (platform === 'airbnb') return '#FF5A5F';
    if (platform === 'booking') return '#003580';
    return '#4285F4'; // Google Blue
}

// LOGIC: Home Page Reviews
// Reverting to static HTML in index.html for home page reviews as per user request

// DATA: Gallery Images
const galleryImages = [
    // --- Wooden Villa ---
    { src: 'assets/images/wooden-villa-swing-3.jpg', caption: 'Wooden Villa — Bedroom', category: 'accommodations' },
    { src: 'assets/images/wooden-villa-swing-4.jpg', caption: 'Wooden Villa — Living Area', category: 'accommodations' },
    { src: 'assets/images/wooden-villa-swing-5.jpg', caption: 'Wooden Villa — Room', category: 'accommodations' },
    { src: 'assets/images/gallery-room-swing.jpg',   caption: 'Wooden Villa — Swing Room', category: 'accommodations' },
    { src: 'assets/images/wooden-villa-bathroom.png', caption: 'Wooden Villa — Bathroom', category: 'accommodations' },
    { src: 'assets/images/villa-exterior.jpg',        caption: 'Wooden Villa — Exterior', category: 'accommodations' },

    // --- White Villa ---
    { src: 'assets/images/white-villa-main-hero.jpg', caption: 'White Villa — Main View', category: 'accommodations' },
    { src: 'assets/images/white-villa-1.jpg',         caption: 'White Villa — Interior', category: 'accommodations' },
    { src: 'assets/images/white-villa-2.jpg',         caption: 'White Villa — Bedroom', category: 'accommodations' },
    { src: 'assets/images/white-villa-3.jpg',         caption: 'White Villa — Living Space', category: 'accommodations' },
    { src: 'assets/images/white-villa-4.jpg',         caption: 'White Villa — Detail', category: 'accommodations' },

    // --- Nature's Nest ---
    { src: 'assets/images/natures-nest-main.jpg',  caption: "Nature's Nest — Main View", category: 'accommodations' },
    { src: 'assets/images/natures-nest-1.jpg',     caption: "Nature's Nest — Interior", category: 'accommodations' },
    { src: 'assets/images/natures-nest-2.jpg',     caption: "Nature's Nest — Living Space", category: 'accommodations' },
    { src: 'assets/images/natures-nest-3.jpg',     caption: "Nature's Nest — Room", category: 'accommodations' },
    { src: 'assets/images/natures-nest-gate.jpg',  caption: "Nature's Nest — Entrance", category: 'accommodations' },

    // --- Tranquil Retreat ---
    { src: 'assets/images/tranquil-retreat-main.jpg', caption: 'Tranquil Retreat — Main View', category: 'accommodations' },
    { src: 'assets/images/tranquil-retreat-1.jpg',    caption: 'Tranquil Retreat — Bedroom', category: 'accommodations' },
    { src: 'assets/images/tranquil-retreat-2.jpg',    caption: 'Tranquil Retreat — Interior', category: 'accommodations' },
    { src: 'assets/images/tranquil-retreat-3.jpg',    caption: 'Tranquil Retreat — Living Area', category: 'accommodations' },
    { src: 'assets/images/tranquil-retreat-4.jpg',    caption: 'Tranquil Retreat — Detail', category: 'accommodations' },
    { src: 'assets/images/tranquil-retreat-gate.jpg', caption: 'Tranquil Retreat — Entrance', category: 'accommodations' },

    // --- Wooden Cottage ---
    { src: 'assets/images/wooden-cottage-1.jpg', caption: 'Wooden Cottage', category: 'accommodations' },
    { src: 'assets/images/wooden-cottage-2.jpg', caption: 'Wooden Cottage', category: 'accommodations' },
    { src: 'assets/images/wooden-cottage-3.jpg', caption: 'Wooden Cottage', category: 'accommodations' },
    { src: 'assets/images/wooden-cottage-4.jpg', caption: 'Wooden Cottage', category: 'accommodations' },
    { src: 'assets/images/wooden-cottage-5.jpg', caption: 'Wooden Cottage', category: 'accommodations' },
    { src: 'assets/images/wooden-cottage-6.jpg', caption: 'Wooden Cottage', category: 'accommodations' },
    { src: 'assets/images/wooden-cottage-7.jpg', caption: 'Wooden Cottage', category: 'accommodations' },
    { src: 'assets/images/wooden-cottage-8.jpg', caption: 'Wooden Cottage', category: 'accommodations' },

    // --- Tent ---
    { src: 'assets/images/tent-new-1.jpg',         caption: 'Tent — Setup', category: 'accommodations' },
    { src: 'assets/images/tent-new-2.jpg',         caption: 'Tent — Interior', category: 'accommodations' },
    { src: 'assets/images/tent-new-3.jpg',         caption: 'Tent — Sleeping Area', category: 'accommodations' },
    { src: 'assets/images/tent-new-4.jpg',         caption: 'Tent — View', category: 'accommodations' },
    { src: 'assets/images/tent-new-5.jpg',         caption: 'Tent — Detail', category: 'accommodations' },
    { src: 'assets/images/tent-new-6.jpg',         caption: 'Tent — Campsite', category: 'accommodations' },
    { src: 'assets/images/gallery-tent-camping.jpg', caption: 'Camping Tent', category: 'accommodations' },

    // --- Facilities ---
    { src: 'assets/images/farm-swing.jpg',          caption: 'Swing and Play Area', category: 'facilities' },
    { src: 'assets/images/outdoor-seating.jpg',     caption: 'Outdoor Meditation Seating', category: 'facilities' },
    { src: 'assets/images/gallery-dining-hall-1.jpg', caption: 'Spacious Dining Hall', category: 'facilities' },
    { src: 'assets/images/gallery-dining-hall-2.jpg', caption: 'Dining Area', category: 'facilities' },
    { src: 'assets/images/farm-new-1752.jpg',       caption: 'Badminton Court', category: 'facilities' },

    // --- Farm Life ---
    { src: 'assets/images/farm-aerial.jpg',               caption: 'Aerial View of Kanchi Farm Stay', category: 'farm-life' },
    { src: 'assets/images/farm-hero.jpg',                 caption: 'Kanchi Farm Stay', category: 'farm-life' },
    { src: 'assets/images/farm-gate.jpg',                 caption: 'Farm Entrance Gate', category: 'farm-life' },
    { src: 'assets/images/gallery-entrance-car.jpg',      caption: 'Farm Entrance', category: 'farm-life' },
    { src: 'assets/images/gallery-entrance-path.jpg',     caption: 'Entrance Pathway', category: 'farm-life' },
    { src: 'assets/images/farm-pathway.jpg',              caption: 'Farm Pathway with Palm Trees', category: 'farm-life' },
    { src: 'assets/images/farm-exterior.jpg',             caption: 'Lush Green Surroundings', category: 'farm-life' },
    { src: 'assets/images/about-farm.jpg',                caption: 'Rustic Farm Charm', category: 'farm-life' },
    { src: 'assets/images/farm-rice-paddy.jpg',           caption: 'Rice Paddy Fields', category: 'farm-life' },
    { src: 'assets/images/gallery-white-building-front.jpg', caption: 'Farm House Front View', category: 'farm-life' },
    { src: 'assets/images/gallery-white-building-side.jpg',  caption: 'Farm House Side View', category: 'farm-life' },
    { src: 'assets/images/gallery-pink-building.jpg',    caption: 'Farm Building', category: 'farm-life' },
    { src: 'assets/images/farm-new-1753.jpg',            caption: 'Garden & Palm Trees', category: 'farm-life' },
    { src: 'assets/images/farm-new-1754.jpg',            caption: 'Farm Pond', category: 'farm-life' },
    { src: 'assets/images/farm-new-1755.jpg',            caption: 'Farm Grounds', category: 'farm-life' },
    { src: 'assets/images/farm-new-1756.jpg',            caption: 'Farm Pathway & Trees', category: 'farm-life' },
    { src: 'assets/images/farm-new-1757.jpg',            caption: 'Farm Garden', category: 'farm-life' },
    { src: 'assets/images/farm-new-1759.jpg',            caption: 'Farm Landscape', category: 'farm-life' },

    // --- Animals ---
    { src: 'assets/images/farm-rabbit-sunset.jpg',   caption: 'Farm Rabbit at Sunset', category: 'animals' },
    { src: 'assets/images/farm-rabbit-closeup.jpg',  caption: 'Rabbit Close-up', category: 'animals' },
    { src: 'assets/images/farm-rabbits-pair.jpg',    caption: 'Pair of Rabbits', category: 'animals' },
    { src: 'assets/images/farm-rabbit-white.jpg',    caption: 'White Rabbit', category: 'animals' },
    { src: 'assets/images/farm-rabbits-feeding.jpg', caption: 'Rabbits Feeding', category: 'animals' },
    { src: 'assets/images/farm-turkeys.jpg',         caption: 'Farm Turkeys', category: 'animals' },
    { src: 'assets/images/farm-chicks-group.jpg',    caption: 'Baby Chicks', category: 'animals' },
    { src: 'assets/images/farm-chicks-enclosure.jpg', caption: 'Chicks in Enclosure', category: 'animals' },
    { src: 'assets/images/farm-chick-closeup.jpg',   caption: 'Baby Chick Close-up', category: 'animals' },
    { src: 'assets/images/farm-dog.jpg',             caption: 'Farm Dog', category: 'animals' },
    { src: 'assets/images/farm-dog-portrait.jpg',    caption: 'German Shepherd Portrait', category: 'animals' },
    { src: 'assets/images/farm-dogs-relaxing.jpg',   caption: 'Farm Dogs Relaxing', category: 'animals' },
];

// LOGIC: Gallery Page
let currentGalleryFilter = 'all';
let currentLightboxImages = [];
let currentLightboxIndex = 0;

function renderGalleryGrid(filter) {
    currentGalleryFilter = filter;
    const grid = document.getElementById('gallery-grid');
    if (!grid) return;

    const filtered = filter === 'all' ? galleryImages : galleryImages.filter(img => img.category === filter);
    currentLightboxImages = filtered;

    grid.innerHTML = filtered.map((img, index) => `
        <div class="gallery-item" onclick="openLightbox(${index})">
            <img src="${img.src}" alt="${img.caption}" loading="lazy">
            <div class="image-overlay"><span>${img.caption}</span></div>
        </div>
    `).join('');

    // Update active filter button
    document.querySelectorAll('.gallery-filter-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.filter === filter);
    });
}

// LOGIC: Lightbox
function initLightbox() {
    const lightbox = document.createElement('div');
    lightbox.id = 'lightbox';
    lightbox.className = 'lightbox';

    const img = document.createElement('img');
    img.id = 'lightbox-img';

    const closeBtn = document.createElement('div');
    closeBtn.className = 'lightbox-close';
    closeBtn.innerHTML = '&times;';

    const prevBtn = document.createElement('div');
    prevBtn.className = 'lightbox-prev';
    prevBtn.innerHTML = '&#8249;';
    prevBtn.addEventListener('click', (e) => { e.stopPropagation(); navigateLightbox(-1); });

    const nextBtn = document.createElement('div');
    nextBtn.className = 'lightbox-next';
    nextBtn.innerHTML = '&#8250;';
    nextBtn.addEventListener('click', (e) => { e.stopPropagation(); navigateLightbox(1); });

    const caption = document.createElement('div');
    caption.className = 'lightbox-caption';
    caption.id = 'lightbox-caption';

    const counter = document.createElement('div');
    counter.className = 'lightbox-counter';
    counter.id = 'lightbox-counter';

    lightbox.appendChild(prevBtn);
    lightbox.appendChild(img);
    lightbox.appendChild(nextBtn);
    lightbox.appendChild(closeBtn);
    lightbox.appendChild(caption);
    lightbox.appendChild(counter);
    document.body.appendChild(lightbox);

    lightbox.addEventListener('click', (e) => {
        if (e.target === lightbox) closeLightbox();
    });

    closeBtn.addEventListener('click', closeLightbox);

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeLightbox();
        if (e.key === 'ArrowLeft') navigateLightbox(-1);
        if (e.key === 'ArrowRight') navigateLightbox(1);
    });
}

function openLightbox(indexOrSrc) {
    const lightbox = document.getElementById('lightbox');
    const img = document.getElementById('lightbox-img');
    const captionEl = document.getElementById('lightbox-caption');
    const counterEl = document.getElementById('lightbox-counter');
    if (!lightbox || !img) return;

    if (typeof indexOrSrc === 'number') {
        currentLightboxIndex = indexOrSrc;
        // If no filtered set (e.g. called from room details), fall back to full list
        if (!currentLightboxImages.length) currentLightboxImages = galleryImages;
        const item = currentLightboxImages[currentLightboxIndex];
        img.src = item.src;
        if (captionEl) captionEl.textContent = item.caption;
        if (counterEl) counterEl.textContent = `${currentLightboxIndex + 1} / ${currentLightboxImages.length}`;
    } else {
        // Direct src string (e.g. from room details page)
        img.src = indexOrSrc;
        if (captionEl) captionEl.textContent = '';
        if (counterEl) counterEl.textContent = '';
        currentLightboxImages = [];
    }

    lightbox.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function navigateLightbox(direction) {
    if (!currentLightboxImages.length) return;
    currentLightboxIndex = (currentLightboxIndex + direction + currentLightboxImages.length) % currentLightboxImages.length;
    openLightbox(currentLightboxIndex);
}

function closeLightbox() {
    const lightbox = document.getElementById('lightbox');
    if (lightbox) {
        lightbox.classList.remove('active');
        document.body.style.overflow = '';
    }
}

// INITIALIZATION
document.addEventListener('DOMContentLoaded', () => {
    renderNavbar();
    renderFooter();
    initLightbox();

    // Page specific logic — match with or without .html extension (clean URLs)
    const path = window.location.pathname.replace(/\.html$/, '');

    if (path.includes('room-details')) {
        loadRoomDetails();
    }

    if (path.includes('reviews')) {
        loadReviews();
    }

    if (path.includes('accommodations')) {
        renderAccommodations();
    }

    if (path.includes('gallery')) {
        renderGalleryGrid('all');
    }

    renderWhatsAppButton();
});

// COMPONENT: Floating WhatsApp Button
function renderWhatsAppButton() {
    const a = document.createElement('a');
    a.href = 'https://wa.me/916383726094';
    a.target = '_blank';
    a.rel = 'noopener noreferrer';
    a.className = 'whatsapp-float';
    a.setAttribute('aria-label', 'Chat on WhatsApp');
    a.innerHTML = `<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
    </svg>`;
    document.body.appendChild(a);
}

// LOGIC: Room Image Slider
function initRoomSlider(images) {
    const hero = document.querySelector('.room-hero');
    if (!hero || !images || images.length <= 1) return;

    // Build slider
    const slider = document.createElement('div');
    slider.className = 'room-hero-slider';
    slider.id = 'room-hero-slider';

    images.forEach(src => {
        const img = document.createElement('img');
        img.src = src;
        img.className = 'room-hero-slide';
        img.alt = 'Room photo';
        slider.appendChild(img);
    });

    // Replace the static hero img
    const existingImg = hero.querySelector('#room-hero-img');
    if (existingImg) existingImg.replaceWith(slider);

    // Prev / Next buttons
    const prevBtn = document.createElement('button');
    prevBtn.className = 'slider-btn slider-btn-prev';
    prevBtn.innerHTML = '&#8249;';
    prevBtn.setAttribute('aria-label', 'Previous photo');

    const nextBtn = document.createElement('button');
    nextBtn.className = 'slider-btn slider-btn-next';
    nextBtn.innerHTML = '&#8250;';
    nextBtn.setAttribute('aria-label', 'Next photo');

    // Dots
    const dotsEl = document.createElement('div');
    dotsEl.className = 'slider-dots';
    images.forEach((_, i) => {
        const dot = document.createElement('button');
        dot.className = 'slider-dot' + (i === 0 ? ' active' : '');
        dot.setAttribute('aria-label', `Go to photo ${i + 1}`);
        dot.addEventListener('click', () => goToSlide(i));
        dotsEl.appendChild(dot);
    });

    hero.appendChild(prevBtn);
    hero.appendChild(nextBtn);
    hero.appendChild(dotsEl);

    let current = 0;

    function goToSlide(n) {
        current = (n + images.length) % images.length;
        slider.style.transform = `translateX(-${current * 100}%)`;
        dotsEl.querySelectorAll('.slider-dot').forEach((d, i) => {
            d.classList.toggle('active', i === current);
        });
    }

    prevBtn.addEventListener('click', () => goToSlide(current - 1));
    nextBtn.addEventListener('click', () => goToSlide(current + 1));

    // Auto-advance every 5s
    setInterval(() => goToSlide(current + 1), 5000);
}

// LOGIC: Availability Calendar (room-details page)
// blockedRanges can be passed directly (already fetched) or omitted to fetch fresh
function loadAvailabilityCalendar(roomId, blockedRanges) {
    const container = document.getElementById('availability-calendar');
    if (!container) return;

    if (blockedRanges !== undefined) {
        renderAvailabilityCalendar(container, blockedRanges);
        return;
    }

    fetch(`channel-manager/availability-api.php?room=${encodeURIComponent(roomId)}`)
        .then(r => r.json())
        .then(data => {
            if (data.error) { container.innerHTML = ''; return; }
            renderAvailabilityCalendar(container, data.blocked || []);
        })
        .catch(() => { container.innerHTML = ''; });
}

function renderAvailabilityCalendar(container, blockedRanges) {
    // Build a Set of blocked date strings for fast lookup
    function buildBlockedSet(ranges) {
        const set = new Set();
        ranges.forEach(([ci, co]) => {
            let cur = new Date(ci + 'T00:00:00');
            const end = new Date(co + 'T00:00:00');
            while (cur < end) {
                set.add(cur.toISOString().split('T')[0]);
                cur.setDate(cur.getDate() + 1);
            }
        });
        return set;
    }

    const blocked = buildBlockedSet(blockedRanges);
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    let html = `
      <div class="avail-cal-wrap">
        <h3 style="margin-bottom:1rem;font-size:1rem;">Availability</h3>
        <div class="avail-legend">
          <span class="avail-dot avail-free"></span> Available
          <span class="avail-dot avail-blocked" style="margin-left:1rem;"></span> Booked
        </div>
    `;

    // Show 3 months starting from today
    for (let m = 0; m < 3; m++) {
        const d = new Date(today.getFullYear(), today.getMonth() + m, 1);
        const year = d.getFullYear();
        const month = d.getMonth();
        const monthName = d.toLocaleString('default', { month: 'long', year: 'numeric' });
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        const firstDow = d.getDay(); // 0=Sun

        html += `<div class="avail-month"><div class="avail-month-title">${monthName}</div>`;
        html += '<div class="avail-grid">';
        ['Su','Mo','Tu','We','Th','Fr','Sa'].forEach(dh => {
            html += `<div class="avail-day-hdr">${dh}</div>`;
        });
        for (let i = 0; i < firstDow; i++) html += '<div></div>';
        for (let day = 1; day <= daysInMonth; day++) {
            const dateStr = `${year}-${String(month+1).padStart(2,'0')}-${String(day).padStart(2,'0')}`;
            const dt = new Date(year, month, day);
            const isPast = dt < today;
            const isBlocked = blocked.has(dateStr);
            let cls = 'avail-day';
            if (isPast)    cls += ' avail-past';
            else if (isBlocked) cls += ' avail-blocked-day';
            else           cls += ' avail-free-day';
            html += `<div class="${cls}">${day}</div>`;
        }
        html += '</div></div>'; // avail-grid + avail-month
    }

    html += '</div>'; // avail-cal-wrap
    container.innerHTML = html;
}

// LOGIC: Accommodations Page
function renderAccommodations() {
    const list = document.getElementById('accommodations-list');
    if (!list) return;

    list.innerHTML = rooms.map(room => `
        <div class="room-card-large">
            <div class="room-img-wrapper">
                <img src="${room.image}" alt="${room.name}">
            </div>
            <div class="room-content">
                <h2>${room.name}</h2>
                <p class="room-desc">${room.shortDescription}</p>
                <div class="room-meta">
                    <span>👥 ${room.capacity}</span>
                    <span>🏷️ ${room.price}</span>
                </div>
                <a href="room-details.html?id=${room.id}" class="btn btn-outline-dark">
                    View Details
                </a>
            </div>
        </div>
    `).join('');
}
